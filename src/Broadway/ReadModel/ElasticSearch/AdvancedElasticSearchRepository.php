<?php
/**
 * Date: 25/01/16
 * Time: 13:27
 */

namespace Broadway\ReadModel\ElasticSearch;


use Broadway\Serializer\SerializerInterface;
use Elasticsearch\Client;
use Elasticsearch\Common\Exceptions\Missing404Exception;

class AdvancedElasticSearchRepository extends ElasticSearchRepository
{
    private $client;
    private $serializer;
    private $index;
    private $class;
    private $notAnalyzedFields;

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
     * {@inheritDoc}
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
            $retval[] = array('term' => array($field => $value));
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
            while(\true){
                $response = $this->client->scroll(['scroll_id' => $scrollId, 'scroll' => '10s']);

                if(count($response['hits']['hits']) > 0){
                    $hits = array_merge($hits, $response['hits']['hits']);
                    $scrollId = $response['_scroll_id'];
                }else{
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
            $hit['_source']['_version'] = $hit['_version'];
        }

        return $this->serializer->deserialize(
            array(
                'class' => $hit['_type'],
                'payload' => $hit['_source'],
            )
        );
    }

}