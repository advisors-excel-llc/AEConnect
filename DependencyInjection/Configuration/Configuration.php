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
        $tree = new TreeBuilder('ae_connect');

        if (method_exists($tree, 'getRootNode')) {
            $root = $tree->getRootNode();
        } else {
            $root = $tree->root('ae_connect');
        }

        $root
             ->children()
                ->arrayNode('paths')
                    ->defaultValue([])
                    ->scalarPrototype()->end()
                ->end()
                ->scalarNode('app_name')->defaultNull()->end()
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
        $tree = new TreeBuilder('connections');

        if (method_exists($tree, 'getRootNode')) {
            $root = $tree->getRootNode();
        } else {
            $root = $tree->root('connections');
        }

        return $root->prototype('array')
                    ->children()
                        ->scalarNode('version')->defaultValue('44.0')->end()
                        ->append($this->buildLoginTree())
                        ->append($this->buildTopicsTree())
                        ->append($this->buildPlatformEventsTree())
                        ->append($this->buildObjectsTree())
                        ->append($this->buildChangeEventsTree())
                        ->append($this->buildPollingTree())
                        ->append($this->buildGenericEventsTree())
                        ->append($this->buildConfigTree())
                    ->end()
                ->end()
        ;
    }

    private function buildLoginTree()
    {
        $tree = new TreeBuilder('login');

        if (method_exists($tree, 'getRootNode')) {
            $root = $tree->getRootNode();
        } else {
            $root = $tree->root('login');
        }

        return $root->children()
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
    }

    private function buildConfigTree()
    {
        $tree = new TreeBuilder('config');

        if (method_exists($tree, 'getRootNode')) {
            $root = $tree->getRootNode();
        } else {
            $root = $tree->root('config');
        }

        return $root->addDefaultsIfNotSet()
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
                            ->scalarNode('replay_provider')
                                ->cannotBeEmpty()
                                ->defaultValue('ae_connect_replay')
                            ->end()
                        ->end()
                    ->end()
                    ->booleanNode('use_change_data_capture')
                        ->defaultTrue()
                        ->setDeprecated(
                            'If use_change_data_capture is true, use change_events instead, otherwise use polling. '.
                            'objects node will be remove in 1.4'
                        )
                    ->end()
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
                    ->scalarNode('connection_logger')->defaultValue('logger')->end()
                    ->arrayNode('app_filtering')
                        ->addDefaultsIfNotSet()
                        ->children()
                            ->booleanNode('enabled')->defaultTrue()->end()
                            ->arrayNode('permitted_objects')
                                ->prototype('scalar')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
        ;
    }

    private function buildTopicsTree()
    {
        $tree = new TreeBuilder('topics');

        if (method_exists($tree, 'getRootNode')) {
            $root = $tree->getRootNode();
        } else {
            $root = $tree->root('topics');
        }

        return $root
                ->prototype('array')
                    ->children()
                        ->scalarNode('type')->isRequired()->end()
                        ->arrayNode('filter')
                            ->prototype('scalar')->end()
                        ->end()
                    ->end()
                ->end()
        ;
    }

    private function buildPlatformEventsTree()
    {
        $tree = new TreeBuilder('platform_events');

        if (method_exists($tree, 'getRootNode')) {
            $root = $tree->getRootNode();
        } else {
            $root = $tree->root('platform_events');
        }

        return $root
                        ->scalarPrototype()
                     ->end()
        ;
    }

    private function buildObjectsTree()
    {
        $tree = new TreeBuilder('objects');

        if (method_exists($tree, 'getRootNode')) {
            $root = $tree->getRootNode();
        } else {
            $root = $tree->root('objects');
        }

        return $root
                        ->setDeprecated(
                            'Use change_events and polling in place of objects, which will be removed in 1.4'
                        )
                        ->scalarPrototype()
                     ->end()
        ;
    }

    private function buildChangeEventsTree()
    {
        $tree = new TreeBuilder('change_events');

        if (method_exists($tree, 'getRootNode')) {
            $root = $tree->getRootNode();
        } else {
            $root = $tree->root('change_events');
        }

        return $root
                    ->scalarPrototype()
                ->end()
        ;
    }

    private function buildPollingTree()
    {
        $tree = new TreeBuilder('polling');

        if (method_exists($tree, 'getRootNode')) {
            $root = $tree->getRootNode();
        } else {
            $root = $tree->root('polling');
        }

        return $root
                        ->scalarPrototype()
                     ->end()
        ;
    }

    private function buildGenericEventsTree()
    {
        $tree = new TreeBuilder('generic_events');

        if (method_exists($tree, 'getRootNode')) {
            $root = $tree->getRootNode();
        } else {
            $root = $tree->root('generic_events');
        }

        return $root
                        ->scalarPrototype()
                     ->end()
        ;
    }
}
