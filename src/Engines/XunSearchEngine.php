<?php
namespace Taxusorg\XunSearchLaravel\Engines;

use Illuminate\Database\Eloquent\SoftDeletes;
use XS as XunSearch;
use XSDocument as XunSearchDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Scout\Engines\Engine;
use Laravel\Scout\Builder;
use Taxusorg\XunSearchLaravel\Contracts\XunSearch as XunSearchContract;

class XunSearchEngine extends Engine
{
    private $server_host = '127.0.0.1';
    private $server_index_host = null;
    private $server_index_port = 8383;
    private $server_search_host = null;
    private $server_search_port = 8384;
    private $default_charset = 'utf-8';

    protected $doc_key_name = 'xun_search_object_id';

    protected $xss = [];

    public function __construct($config = [])
    {
        if (isset($config['server_host'])) {
            $this->server_host = $config['server_host'];
        }
        if (isset($config['server_index_host'])) {
            $this->server_index_host = $config['server_index_host'];
        }
        if (isset($config['server_index_port'])) {
            $this->server_index_port = $config['server_index_port'];
        }
        if (isset($config['server_search_host'])) {
            $this->server_search_host = $config['server_search_host'];
        }
        if (isset($config['server_search_port'])) {
            $this->server_search_port = $config['server_search_port'];
        }
        if (isset($config['default_charset'])) {
            $this->default_charset = $config['default_charset'];
        }
        if (isset($config['doc_key_name']) && $config['doc_key_name']) {
            $this->doc_key_name = $config['doc_key_name'];
        }
    }

    /**
     * Update the given model in the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     * @throws
     */
    public function update($models)
    {
        if ($this->usesSoftDelete($models->first()))
            $models = $this->addSoftDeleteData($models);

        foreach ($models as $model) {
            $doc = new XunSearchDocument();
            $doc->setField($this->doc_key_name, $model->getScoutKey());
            $doc->setFields(array_merge(
                $model->toSearchableArray(), $model->scoutMetadata()
            ));
            $this->getXS($model)->index->update($doc);
        }
    }

    /**
     * Remove the given model from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function delete($models)
    {
        if (!$models->isEmpty())
            $this->getXS($models->first())->index->del(
                $models->map(function ($model) {
                    return $model->getScoutKey();
                })->values()->all()
            );
    }

    /**
     * Delete all data.
     *
     * @param Model $model
     */
    public function clean(Model $model)
    {
        $this->getXS($model)->index->clean();
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return mixed
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder, array_filter([
            'hitsPerPage' => $builder->limit,
        ]));
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  int  $perPage
     * @param  int  $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->performSearch($builder, array_filter([
            'hitsPerPage' => $perPage,
            'page' => $page - 1,
        ]));
    }

    protected function performSearch(Builder $builder, array $options = [])
    {
        $search = $this->getXS($builder->model)->search;

        if (isset($options['hitsPerPage'])) {
            if (isset($options['page']) && $options['page'] > 0) {
                $search->setLimit($options['hitsPerPage'], $options['hitsPerPage'] * $options['page']);
            }else{
                $search->setLimit($options['hitsPerPage']);
            }
        }

        if ($builder->callback) {
            return call_user_func(
                $builder->callback,
                $search,
                $builder->query,
                $options
            );
        }

        $search->setFuzzy(boolval($builder->fuzzy))
            ->setQuery($this->buildQuery($builder));

        $ranges = collect($builder->ranges)->map(function ($value, $key) use ($search, $builder) {
            $search->addRange($key, $value['from'], $value['to']);
        });

        return ['docs' => $search->search(), 'total' => $search->getLastCount()];
    }

    protected function buildQuery(Builder $builder)
    {
        $query = $builder->query;

        $wheres = collect($builder->wheres)->map(function ($value, $key) use (&$query) {
            $query .= ' ' . $key.':'.$value;
        });

        return $query;
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        return collect($results['docs'])->pluck($this->doc_key_name)->values();
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        if (count($results['docs']) === 0) {
            return Collection::make();
        }

        $keys = collect($results['docs'])->pluck($this->doc_key_name)->values()->all();

        $models = $model->getScoutModelsByIds(
            $builder, $keys
        )->keyBy(function ($model) {
            return $model->getScoutKey();
        });

        return Collection::make($results['docs'])->map(function ($doc) use ($model, $models) {
            $key = $doc[$this->doc_key_name];

            if (isset($models[$key])) {
                return $models[$key];
            }

            return false;
        })->filter()->values();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed  $results
     * @return int
     */
    public function getTotalCount($results)
    {
        return $results['total'];
    }

    /**
     * Get Xun Search Object.
     *
     * @param Model $model
     * @return XunSearch
     * @throws
     */
    protected function getXS(Model $model)
    {
        $app_name = $model->searchableAs();

        if (isset($this->xss[$app_name]))
            return $this->xss[$app_name];

        return $this->xss[$app_name] = new XunSearch($this->buildIni($app_name, $model));
    }

    /**
     * Build ini.
     * @param string $app_name
     * @param XunSearchContract|Model $model
     * @return string
     * @throws \Error
     */
    protected function buildIni(string $app_name, XunSearchContract $model)
    {
        $str =
        'project.name = ' . $app_name . PHP_EOL.
        'project.default_charset = ' . $this->default_charset . PHP_EOL.
        'server.index = ' . ($this->server_index_host ? $this->server_index_host . ':' :
            ($this->server_host ? $this->server_host . ':' : '')) . $this->server_index_port . PHP_EOL.
        'server.search = ' . ($this->server_search_host ? $this->server_search_host . ':' :
            ($this->server_host ? $this->server_host . ':' : '')) . $this->server_search_port . PHP_EOL.
        '';

        $str .= PHP_EOL . '[' . $this->doc_key_name . ']' . PHP_EOL.
            'type = id'.PHP_EOL;

        $types = $model->searchableFieldsType();

        $count_title = $count_body = 0;
        foreach ($types as $key=>$value) {
            if ($key == $this->doc_key_name)
                throw new \Error("The field '$key' as same as XunSearch doc_key_name in Engine.
                You can change XunSearch doc_key_name in app->config['xunsearch']['doc_key_name']");

            if (isset($types[$key]['type'])) {
                if ($types[$key]['type'] == XunSearchContract::XUNSEARCH_TYPE_ID)
                    throw new \Error("The field '$key' must not be 'id'.
                    Type 'id' has be setting as default in engine.
                    Set the type as 'numeric' or 'string' in Model->searchableFieldsType(),
                    if you want it to be use in Searchable");

                if ($types[$key]['type'] == XunSearchContract::XUNSEARCH_TYPE_TITLE)
                    $count_title++;

                elseif ($types[$key]['type'] == XunSearchContract::XUNSEARCH_TYPE_BODY)
                    $count_body++;
            }

            if ($count_title > 1 || $count_body > 1)
                throw new \Error("'title' or 'body' can only be set once.
                Fix it in Model->searchableFieldsType()");

            $str .= PHP_EOL . "[$key]" . PHP_EOL;
            if (isset($types[$key]['type'])) $str .= 'type = ' . $types[$key]['type'] . PHP_EOL;
            if (isset($types[$key]['index'])) $str .= 'index = ' . $types[$key]['index'] . PHP_EOL;
            if (isset($types[$key]['tokenizer'])) {
                if (in_array($types[$key]['tokenizer'], [
                    XunSearchContract::XUNSEARCH_TOKENIZER_FULL,
                    XunSearchContract::XUNSEARCH_TOKENIZER_NONE,
                ])) {
                    $str .= 'tokenizer = ' . $types[$key]['tokenizer'] . PHP_EOL;
                } elseif (isset($types[$key]['tokenizer_value']) && (int) $types[$key]['tokenizer_value'] > 0) {
                    $str .= 'tokenizer = ' .$types[$key]['tokenizer'].
                        '('. (int) $types[$key]['tokenizer_value'].')' . PHP_EOL;
                } else {
                    throw new \Error("The field '$key' has wrong tokenizer.");
                }
            } elseif (isset($types[$key]['tokenizer_value']) && (int) $types[$key]['tokenizer_value'] > 0) {
                $str .= 'tokenizer = ' .XunSearchContract::XUNSEARCH_TOKENIZER_SCWS.
                    '('. (int) $types[$key]['tokenizer_value'].')' . PHP_EOL;
            }
        }

        if ($this->usesSoftDelete($model))
            $str = $this->addSoftDeleteField($str);

        return $str;
    }

    protected function addSoftDeleteField($init)
    {
        $init .= PHP_EOL . '[__soft_deleted]' . PHP_EOL;
        $init .= 'type = ' . XunSearchContract::XUNSEARCH_TYPE_NUMERIC . PHP_EOL;
        $init .= 'index = ' . XunSearchContract::XUNSEARCH_INDEX_SELF . PHP_EOL;
        $init .= 'tokenizer = ' . XunSearchContract::XUNSEARCH_TOKENIZER_FULL . PHP_EOL;

        return $init;
    }

    protected function addSoftDeleteData($models)
    {
        $models->each->pushSoftDeleteMetadata();

        return $models;
    }

    protected function usesSoftDelete($model)
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model))
             && config('scout.soft_delete', false);
    }
}
