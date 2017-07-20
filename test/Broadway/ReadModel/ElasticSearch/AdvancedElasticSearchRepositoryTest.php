<?php
/**
 * Date: 18/07/17
 * Time: 16:52
 */

namespace Broadway\ReadModel\ElasticSearch;


use Broadway\ReadModel\RepositoryTestCase;
use Broadway\Serializer\SerializerInterface;
use Broadway\Serializer\SimpleInterfaceSerializer;
use Elasticsearch\Client;


class AdvancedElasticSearchRepositoryTest extends RepositoryTestCase
{
    /**
     * @var Client
     */
    protected $client;

    protected function createRepository()
    {
        $this->client = new Client(array('hosts' => array('localhost:9200')));
        $this->client->indices()->create(array('index' => 'test_test_index'));
        $this->client->cluster()->health(
            array('index' => 'test_test_index', 'wait_for_status' => 'yellow', 'timeout' => '10s')
        );

        return $this->createElasticSearchRepository(
            $this->client,
            new SimpleInterfaceSerializer(),
            'test_index',
            'Broadway\ReadModel\RepositoryTestReadModel',
            'test'
        );
    }

    protected function createElasticSearchRepository(
        Client $client,
        SerializerInterface $serializer,
        $index,
        $class,
        $env
    ) {
        return new AdvancedElasticSearchRepository($client, $serializer, $index, $class, [], $env);
    }

    public function tearDown()
    {
        $this->client->indices()->delete(array('index' => 'test_test_index'));

        if ($this->client->indices()->exists(array('index' => 'test_non_analyzed_index'))) {
            $this->client->indices()->delete(array('index' => 'test_non_analyzed_index'));
        }

        unset($this->repository);
    }
}