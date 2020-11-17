<?php

namespace TheHome\StatamicElasticsearch;

use Illuminate\Support\Collection;
use Statamic\Search\QueryBuilder;

class Query extends QueryBuilder
{
    protected $total;

    public function getSearchResults(string $query): Collection
    {
        $result = $this->index->searchUsingApi(
            $query,
            $this->limit,
            $this->offset,
        );
        
        $this->total = $result['total'];

        return $result['hits'];
    }

    public function getItems()
    {
        return $this->getBaseItems();
    }

    public function getTotal() : int {
        return $this->total;
    }
}