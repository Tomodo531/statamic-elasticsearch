<?php

namespace TheHome\StatamicElasticsearch;

use Statamic\Search\Index as BaseIndex;
use TheHome\StatamicElasticsearch\SearchTransformers;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class Index extends BaseIndex
{  
  /**
   * client
   *
   * @var \Elasticsearch\Client
   */
  protected $client;
  
  protected $elastic_pagination;

  const DRIVER_NAME = 'elasticsearch';
  
  /**
   * __construct
   *
   * @param  \Elasticsearch\Client $client
   * @param  string $name
   * @param  array $config
   * @return void
   */
  public function __construct(\Elasticsearch\Client $client, string $name, array $config)
  {
    $this->client = $client;
    parent::__construct($name, $config);
  }
  
  /**
   * search
   *
   * @param  string $query
   * @return Index
   */
  public function search($query) : Query 
  {
    return (new Query($this))->query($query);
  }

  /**
   * useElasticPagination
   *
   * @return \TheHome\StatamicElasticsearch\Index
   */
  public function useElasticPagination() : self 
  {
    $this->elastic_pagination = true;
    
    return $this;
  }

  /**
   * isUsingElasticPagination
   *
   * @return bool
   */
  public function isUsingElasticPagination() : bool 
  {
    return (bool) $this->elastic_pagination;
  }
  
  /**
   * delete
   *
   * @param  mixed $document
   * @return void
   */
  public function delete($document) : void
  {
    $params = $this->indexName();
    $params['id'] = $document->reference();
    $this->client->delete($params);
  }

  public function exists() : bool
  {
    return $this->client->indices()->exists($this->indexName());
  }

  protected function insertDocuments(\Statamic\Search\Documents $documents) : void
  {
    if (!$this->exists()) {
      $this->createIndex();
    }

    $transforms = $this->config['transforms'] ?? [];
    $transformers = SearchTransformers::resolve();

    $chunks = $documents->chunk(10);

    foreach ($chunks as $chunk) {
      $params = [];
      $chunk->each(function ($item, $key) use (
        &$params,
        $transforms,
        $transformers
      ) {
        foreach ($transforms as $fieldName => $funcName) {
          if (!empty($transformers[$funcName]) && !empty($item[$fieldName])) {
            $item[$fieldName] = $transformers[$funcName]($item[$fieldName]);
          }
        }

        $params['body'][] = [
          'index' => [
            '_index' => $this->name(),
            '_id' => $key,
          ],
        ];
        $params['body'][] = $item;
      });

      $this->client->bulk($params);
    }
  }

  protected function deleteIndex() : void
  {
    if ($this->client->indices()->exists($this->indexName())) {
      $this->client->indices()->delete($this->indexName());
    }
  }
  
  /**
   * searchUsingApi
   *
   * @param  string $query
   * @param  int $limit
   * @param  int $offset
   * @return array
   */
  public function searchUsingApi(string $query, $limit, int $offset = 0) : array
  {
    $limit = $limit ?? 100;
    $params = $this->indexName();
    $fields = array_diff($this->config['fields'], ['status']);
    
    if ($this->isUsingElasticPagination()) {
      $params['body'] = [
        'from' => $offset,
        'size' => $limit,
        '_source' => false,
        'query' => [
          'bool' => [
            'filter' => ['term' => ['status' => 'published']],
            'must' => [
              'multi_match' => [
                'query' => $query,
                'fields' => $fields,
              ]
            ]
          ]
        ],
      ];
    } else {
      $params['body'] = [
        'size' => 500,
        '_source' => false,
        'query' => [
          'multi_match' => [
            'query' => $query,
            'fields' => $fields,
          ],
        ],
      ];
    }

    $response = $this->client->search($params);

    $hits = collect($response['hits']['hits'])->map(function ($hit) {
      $hit['id'] = $hit['_id'];
      $hit['search_score'] = $hit['_score'];

      return $hit;
    });
    
    return [
        'total' => $response['hits']['total']['value'],
        'hits' => $hits
    ];

  }

  protected function indexName() : array
  {
    return ['index' => $this->name()];
  }

  protected function createIndex() : void
  {
    $params = $this->indexName();
    $params['body'] = [
      "settings" => [
        "analysis" => [
          "analyzer" => [
            "default" => [
              "type" => $this->config['analyzer'] ?? 'standard',
            ],
          ],
        ],
      ],
      "mappings" => [
        "properties" => [
          "status" => ["type" => "keyword"]
        ] 
      ]
    ];

    $this->client->indices()->create($params);
  }

}
