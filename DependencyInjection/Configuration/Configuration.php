<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/5/18
 * Time: 5:51 PM
 */

namespace AE\ConnectBundle\DependencyInjection\Configuration;

use AE\SalesforceRestSdk\Bayeux\Extension\ReplayExtension;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder()
    {
        $tree = new TreeBuilder();

        $tree->root('ae_connect')
             ->children()
                ->arrayNode('paths')
                    ->defaultValue([])
                    ->scalarPrototype()->end()
                ->end()
                ->scalarNode('default_connection')
                    ->defaultValue('default')
                ->end()
                ->scalarNode('enqueue')
                    ->defaultValue('default')
                ->end()
             ->end()
             ->append($this->buildConnectionTree())
            ->validate()
                ->ifTrue(function ($value) {
                    $default = $value['default_connection'];

                    return !empty($value['connections']) && !array_key_exists($default, $value['connections']);
                })
                ->thenInvalid('The value given for `default_connection` is not named in the `connections` array.')
            ->end()
        ;

        return $tree;
    }

    private function buildConnectionTree()
    {
        $tree = new TreeBuilder();

        $node = $tree->root('connections')
                ->prototype('array')
                    ->children()
                        ->append($this->buildLoginTree())
                        ->append($this->buildTopicsTree())
                        ->append($this->buildPlatformEventsTree())
                        ->append($this->buildObjectsTree())
                        ->append($this->buildGenericEventsTree())
                        ->append($this->buildConfigTree())
                    ->end()
                ->end()
        ;

        return $node;
    }

    private function buildLoginTree()
    {
        $tree = new TreeBuilder();

        $node = $tree->root('login')
                ->children()
                    ->scalarNode('key')->end()
                    ->scalarNode('secret')->end()
                    ->scalarNode('username')->end()
                    ->scalarNode('password')->end()
                    ->scalarNode('url')->defaultValue('https://login.salesforce.com')->end()
                    ->scalarNode('entity')->end()
                ->end()
                ->validate()
                    ->ifTrue(function ($value) {
                        return empty($value['entity']) && (empty($value['username']) || empty($value['password']));
                    })
                    ->thenInvalid('Either a database entity or a username and password must be provided')
                ->end()
        ;

        return $node;
    }

    private function buildConfigTree()
    {
        $tree = new TreeBuilder();

        $node = $tree->root('config')
                ->addDefaultsIfNotSet()
                ->children()
                    ->scalarNode('replay_start_id')
                        ->cannotBeEmpty()
                        ->defaultValue(ReplayExtension::REPLAY_SAVED)
                        ->validate()
                            ->ifTrue(function ($value) {
                                return !is_numeric($value) || $value < -2;
                            })
                            ->thenInvalid('replay_start_id must be a numeric value no less than -2.')
                        ->end()
                    ->end()
                    ->arrayNode('cache')
                        ->addDefaultsIfNotSet()
                        ->children()
                            ->scalarNode('metadata_provider')
                                ->cannotBeEmpty()
                                ->defaultValue('ae_connect_metadata')
                            ->end()
                            ->scalarNode('auth_provider')
                                ->cannotBeEmpty()
                                ->defaultValue('ae_connect_auth')
                            ->end()
                        ->end()
                    ->end()
                    ->booleanNode('use_change_data_capture')->defaultTrue()->end()
                    ->scalarNode('bulk_api_min_count')
                        ->defaultValue(100000)
                        ->cannotBeEmpty()
                        ->validate()
                            ->ifTrue(function ($value) {
                                return !is_int($value) || $value < 0;
                            })
                            ->thenInvalid('The bulk_api_min_count must be an integer greater than 0')
                        ->end()
                    ->end()
                ->end()
        ;

        return $node;
    }

    private function buildTopicsTree()
    {
        $tree = new TreeBuilder();

        $node = $tree->root('topics')
                ->prototype('array')
                    ->children()
                        ->scalarNode('type')->isRequired()->end()
                        ->arrayNode('filter')
                            ->prototype('scalar')->end()
                        ->end()
                    ->end()
                ->end()
        ;

        return $node;
    }

    private function buildPlatformEventsTree()
    {
        $tree = new TreeBuilder();

        $node = $tree->root('platform_events')
            ->scalarPrototype()->end()
            ;

        return $node;
    }

    private function buildObjectsTree()
    {
        $tree = new TreeBuilder();

        $node = $tree->root('objects')
                     ->scalarPrototype()->end()
        ;

        return $node;
    }

    private function buildGenericEventsTree()
    {
        $tree = new TreeBuilder();

        $node = $tree->root('generic_events')
            ->scalarPrototype()->end();

        return $node;
    }
}
