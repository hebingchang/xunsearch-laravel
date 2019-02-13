<?php
namespace Taxusorg\XunSearchLaravel;

use Laravel\Scout\Builder;

trait XunSearchTrait
{
    /**
     * Boot the trait.
     *
     * @return void
     */
    public static function bootXunSearchTrait()
    {
        (new static)->registerXunSearchMacros();
    }

    public function registerXunSearchMacros()
    {
        $this->registerSearchableRangeSearch();
        $this->registerSearchableOrderSearch();
        $this->registerSearchableFuzzy();
    }

    public function registerSearchableRangeSearch()
    {
        Builder::macro('range', function ($word, $from, $to) {
            $this->ranges[$word]['from'] = $from;
            $this->ranges[$word]['to'] = $to;

            return $this;
        });
    }

    public function registerSearchableOrderSearch()
    {
        Builder::macro('order', function ($by, $asc = false) {
            $this->order = [$by, $asc];
            return $this;
        });
    }

    public function registerSearchableFuzzy()
    {
        Builder::macro('fuzzy', function ($fuzzy = true) {
            $this->fuzzy = (bool) $fuzzy;

            return $this;
        });
    }
}
