<?php

/*
 * This file is part of the broadway/broadway package.
 *
 * (c) Qandidate.com <opensource@qandidate.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Broadway\ReadModel\ElasticSearch;

use Broadway\TestCase;

class ElasticSearchRepositoryFactoryTest extends TestCase
{
    /**
     * @test
     */
    public function it_creates_an_elastic_search_repository()
    {
        $serializer = $this->getMockBuilder('Broadway\Serializer\SerializerInterface')->getMock();
        $client     = $this->getMockBuilder('\Elasticsearch\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $env = 'test';

        $repository = new ElasticSearchRepository($client, $serializer, 'test', 'Class', [], $env);
        $factory    = new ElasticSearchRepositoryFactory($client, $serializer, $env);

        $this->assertEquals($repository, $factory->create('test', 'Class'));
    }

    /**
     * @test
     */
    public function it_creates_an_elastic_search_repository_containing_index_metadata()
    {
        $serializer = $this->getMockBuilder('Broadway\Serializer\SerializerInterface')->getMock();
        $client     = $this->getMockBuilder('\Elasticsearch\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $env = 'test';

        $repository = new ElasticSearchRepository($client, $serializer, 'test', 'Class', array('id'), $env);
        $factory    = new ElasticSearchRepositoryFactory($client, $serializer, $env);

        $this->assertEquals($repository, $factory->create('test', 'Class', array('id')));
    }
}
