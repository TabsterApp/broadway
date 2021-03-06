<?php

/*
 * This file is part of the broadway/broadway package.
 *
 * (c) Qandidate.com <opensource@qandidate.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Broadway\Bundle\BroadwayBundle\DependencyInjection;

use Doctrine\DBAL\Version;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * Configuration definition.
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritDoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode    = $treeBuilder->root('broadway');

        $rootNode
            ->children()
                ->arrayNode('command_handling')
                    ->addDefaultsIfNotSet()
                    ->beforeNormalization()
                        ->always(function (array $v) {
                            if (isset($v['logger']) && $v['logger']) {
                                // auditing requires event dispatching
                                $v['dispatch_events'] = true;
                            }

                            return $v;
                        })
                    ->end()
                    ->children()
                        ->booleanNode('dispatch_events')
                            ->defaultFalse()
                        ->end()
                        ->scalarNode('logger')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('event_store')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('dbal')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('table')
                                    ->defaultValue('events')
                                ->end()
                                ->scalarNode('snapshot_table')
                                    ->defaultValue('snapshots')
                                ->end()
                                ->scalarNode('connection')
                                    ->defaultValue('default')
                                ->end()
                                ->booleanNode('use_binary')
                                    ->defaultFalse()
                                    ->validate()
                                    ->ifTrue()
                                        ->then(function ($v) {
                                            if (Version::compare('2.5.0') >= 0) {
                                                throw new InvalidConfigurationException(
                                                    'The Binary storage is only available with Doctrine DBAL >= 2.5.0'
                                                );
                                            }

                                            return $v;
                                        })
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('saga')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('repository')
                            ->values(array('in_memory', 'mongodb'))
                            ->defaultValue('mongodb')
                        ->end()
                        ->arrayNode('mongodb')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->arrayNode('connection')
                                    ->children()
                                        ->scalarNode('dsn')->defaultNull()->end()
                                        ->scalarNode('database')->defaultNull()->end()
                                        ->arrayNode('options')
                                            ->prototype('scalar')->end()
                                        ->end()
                                    ->end()
                                ->end()
                                ->scalarNode('storage_suffix')->defaultNull()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('read_model')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('repository')
                            ->values(array('in_memory', 'elasticsearch'))
                            ->defaultValue('elasticsearch')
                        ->end()
                        ->arrayNode('elasticsearch')
                            ->addDefaultsIfNotSet()
                            ->children()
                            ->arrayNode('hosts')
                                ->prototype('array')
                                    ->children()
                                        ->scalarNode("host")
                                        ->end()
                                        ->scalarNode("scheme")
                                        ->end()
                                        ->scalarNode("user")
                                        ->end()
                                        ->scalarNode("pass")
                                        ->end()
                                        ->scalarNode("port")
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()
                            ->arrayNode('connectionParams')
                                ->children()
                                    ->arrayNode('auth')
                                        ->prototype('scalar')->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
