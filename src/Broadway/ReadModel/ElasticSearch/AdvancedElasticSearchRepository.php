<?php
/**
 * Date: 25/01/16
 * Time: 13:27
 */

namespace Broadway\ReadModel\ElasticSearch;


use Assert\Assertion;
use Broadway\ReadModel\ReadModelInterface;
use Broadway\Serializer\SerializerInterface;
use Elasticsearch\Client;
use Elasticsearch\Common\Exceptions\Conflict409Exception;
use Elasticsearch\Common\Exceptions\Missing404Exception;

class AdvancedElasticSearchRepository extends ElasticSearchRepository
{
    private $client;
    private $serializer;
    private $index;
    private $class;
    private $keywordFields;

    private $models = [];
    private $versions = [];
    private $changed = [];

    /**
     * @param string $index
     * @param string $class
     * @param array $keywordFields = array
     */
    public function __construct(
        Client $client,
        SerializerInterface $serializer,
        $index,
        $class,
        array $keywordFields = array(),
        $environment
    ) {
        parent::__construct($client, $serializer, $index, $class, $keywordFields, $environment);

        $this->client = $client;
        $this->serializer = $serializer;
        $this->index = $environment.'_'.$index;
        $this->class = $class;
        $this->keywordFields = $keywordFields;
        $this->models = [];
        $this->versions = [];
    }

    /**
     * @param ReadModelInterface $data
     * @param bool $flush
     * @throws Conflict409Exception
     */
    public function save(ReadModelInterface $data, $flush = true)
    {
        Assertion::isInstanceOf($data, $this->class);

        $this->models[$data->getId()] = $data;
        $this->changed[$data->getId()] = true;

        if ($flush) {
            $this->persist($data);
        }
    }

    /**
     * Actually persist in memory readmodels to elasticsearch
     * @throws Conflict409Exception
     */
    protected function persist(ReadModelInterface $data)
    {
        $serializedReadModel = $this->serializer->serialize($data);

        $params = [
            'index' => $this->index,
            'type' => $serializedReadModel['class'],
            'id' => $data->getId(),
            'body' => $serializedReadModel['payload'],
            "refresh" => "true",
        ];

        if (isset($this->versions[$data->getId()])) {
            $params['version'] = $this->versions[$data->getId()];
        } else {
            $this->versions[$data->getId()] = 0;
        }

        try {
            $this->client->index($params);
        } catch (Conflict409Exception $e) {
            unset($this->versions[$data->getId()]); // make sure to remove in memory data because it's not up to date
            unset($this->models[$data->getId()]);

            throw $e;// Enable retrying upstream
        }

        $this->versions[$data->getId()] += 1;
        unset($this->changed[$data->getId()]); // Make sure readmodels are only persisted when actually changed
    }

    /**
     *  Persist all changed in memory models
     */
    public function persistAll()
    {
        foreach ($this->changed as $modelKey => $isChanged) {
            if ($isChanged) {
                $this->persist($this->models[$modelKey]);
            }
        }
    }

    public function remove($id)
    {
        parent::remove($id);

        if (array_key_exists((string)($id), $this->models)) {
            unset($this->models[(string)$id]);
            unset($this->versions[(string)$id]);
        }
    }


    /**
     * @param string $id
     * @return mixed|null
     */
    public function find($id)
    {
        if (array_key_exists((string)($id), $this->models)) {
            return $this->models[(string)$id];
        }

        $params = array(
            'index' => $this->index,
            'type' => $this->class,
            'id' => $id,
        );

        try {
            $result = $this->client->get($params);
        } catch (Missing404Exception $e) {
            return null;
        }

        return $this->deserializeHit($result);
    }

    /**
     * @param array $fields
     * @return array
     */
    public function findBy(array $fields)
    {
        if (empty($fields)) {
            return array();
        }

        $inMemory = $this->findByInMemory($fields);
        if (!empty($inMemory)) {
            return $inMemory;
        }


        return $this->query($this->buildFindByQuery($fields));
    }

    /**
     * Check if object exists in memory.
     * Use reflection to enable searching for private/protected properties as well
     *
     * @param array $fields
     * @return array
     */
    protected function findByInMemory(array $fields)
    {
        return array_values(
            array_filter(
                $this->models,
                function ($model) use ($fields) {
                    $refClass = new \ReflectionClass(get_class($model));
                    foreach ($fields as $field => $searchValue) {
                        if (property_exists(get_class($model), $field)) {
                            $property = $refClass->getProperty($field);
                            $property->setAccessible(true);
                            $modelValue = $property->getValue($model);
                            if (is_array($modelValue) && !in_array($searchValue, $modelValue)) {
                                return false;
                            } elseif ($modelValue !== $searchValue) {
                                return false;
                            }
                        } else {
                            return false;
                        }
                    }

                    return true;
                }
            )
        );
    }

    private function buildFindByQuery(array $fields)
    {
        return array(
            'bool' => array(
                'must' => array(
                    'match_all' => (object)array(),
                ),
                'filter' => $this->buildFilter($fields),
            ),
        );
    }

    private function buildFilter(array $filter)
    {
        $retval = array();

        foreach ($filter as $field => $value) {
            if (is_array($value)) {
                $retval[] = array('terms' => array($field => $value));
            } else {
                $retval[] = array('term' => array($field => $value));
            }
        }

        return array($retval);
    }

    protected function query(array $query)
    {
        return $this->searchAndDeserializeHits(
            array(
                'index' => $this->index,
                'body' => array(
                    'query' => $query,
                ),
                'size' => 500,
                'scroll' => '10s',
                "version" => true,
            )
        );
    }

    private function searchAndDeserializeHits(array $query)
    {
        try {
            $docs = $this->client->search($query);
            $scrollId = $docs['_scroll_id'];

            $hits = $docs['hits']['hits'];
            while (\true) {
                $response = $this->client->scroll(['scroll_id' => $scrollId, 'scroll' => '10s']);

                if (count($response['hits']['hits']) > 0) {
                    $hits = array_merge($hits, $response['hits']['hits']);
                    $scrollId = $response['_scroll_id'];
                } else {
                    break;
                }
            }
        } catch (Missing404Exception $e) {
            return array();
        }

        if (empty($hits)) {
            return array();
        }

        return $this->deserializeHits($hits);
    }


    /**
     * @param array $hits
     * @return array
     */
    private function deserializeHits(array $hits)
    {
        return array_map(array($this, 'deserializeHit'), $hits);
    }

    /**
     * @param array $hit
     * @return mixed
     */
    private function deserializeHit(array $hit)
    {
        if (array_key_exists('_version', $hit)) {
            $this->versions[$hit['_id']] = $hit['_version'];
        }

        $model = $this->serializer->deserialize(
            array(
                'class' => $hit['_type'],
                'payload' => $hit['_source'],
            )
        );

        $this->models[$hit['_id']] = $model;

        return $model;
    }

    /**
     * @return bool
     */
    public function indexExists()
    {
        $indexParams = ['index' => $this->index];

        return $this->client->indices()->exists($indexParams);
    }

}