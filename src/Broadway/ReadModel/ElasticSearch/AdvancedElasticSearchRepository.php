<?php
/**
 * Date: 25/01/16
 * Time: 13:27
 */

namespace Broadway\ReadModel\ElasticSearch;


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
    private $notAnalyzedFields;

    private $models = [];
    private $versions = [];

    /**
     * @param string $index
     * @param string $class
     * @param array $notAnalyzedFields = array
     */
    public function __construct(
        Client $client,
        SerializerInterface $serializer,
        $index,
        $class,
        array $notAnalyzedFields = array()
    ) {
        parent::__construct($client, $serializer, $index, $class, $notAnalyzedFields);

        $this->client = $client;
        $this->serializer = $serializer;
        $this->index = $index;
        $this->class = $class;
        $this->notAnalyzedFields = $notAnalyzedFields;
    }

    /**
     * @param ReadModelInterface $data
     * @param bool $flush
     * @throws Conflict409Exception
     */
    public function save(ReadModelInterface $data, $flush = true)
    {
        $this->models[$data->getId()] = $data;

        if ($flush) {
            $this->flush();
        }
    }

    public function flush()
    {
        foreach ($this->models as $model) {
            $serializedReadModel = $this->serializer->serialize($model);

            $version = isset($this->versions[$model->getId()]) ? $this->versions[$model->getId()] : 0;

            $params = [
                'index' => $this->index,
                'type' => $serializedReadModel['class'],
                'id' => $model->getId(),
                'refresh' => true,
                'body' => $serializedReadModel['payload'],
                'version' => $version
            ];

            $this->client->index($params);

            $this->versions[$model->getId()] = $version + 1;
        }

        $this->models = [];
        $this->versions = [];
    }

    /**
     * @param string $id
     * @return mixed|null
     */
    public function find($id)
    {
        if (isset($this->models[$id])) {
            return $this->models[$id];
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

        return $this->query($this->buildFindByQuery($fields));
    }

    private function buildFindByQuery(array $fields)
    {
        return array(
            'filtered' => array(
                'query' => array(
                    'match_all' => array(),
                ),
                'filter' => $this->buildFilter($fields)
            )
        );
    }

    private function buildFilter(array $filter)
    {
        $retval = array();

        foreach ($filter as $field => $value) {
            if(is_array($value)){
                $retval[] = array('terms' => array($field => $value));
            }else{
                $retval[] = array('term' => array($field => $value));
            }
        }

        return array('and' => $retval);
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
        if(array_key_exists($hit['_id'], $this->models)){//Model already exists in memory
            return $this->models[$hit['_id']];
        }

        if (array_key_exists('_version', $hit)) {
            $this->versions[$hit['_id']] = $hit['_version'];
        }

        return $this->serializer->deserialize(
            array(
                'class' => $hit['_type'],
                'payload' => $hit['_source'],
            )
        );
    }

}