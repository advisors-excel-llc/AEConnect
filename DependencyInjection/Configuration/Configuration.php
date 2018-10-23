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
                    ->isRequired()
                    ->scalarPrototype()->end()
              ->end()
             ->end()
             ->append($this->buildConnectionTree());

        return $tree;
    }

    private function buildConnectionTree()
    {
        $tree = new TreeBuilder();

        $node = $tree->root('connections')
                ->prototype('array')
                    ->children()
                        ->booleanNode('is_default')->defaultTrue()->end()
                        ->append($this->buildLoginTree())
                        ->append($this->buildTopicsTree())
                        ->append($this->buildPlatformEventsTree())
                        ->append($this->buildObjectsTree())
                        ->append($this->buildGenericEventsTree())
                        ->append($this->buildConfigTree())
                    ->end()
                ->end()
            ->validate()
                ->ifTrue(function ($value) {
                    $count = 0;

                    foreach ($value as $values) {
                        if ($values['is_default']) {
                            ++$count;
                        }
                    }

                    return count(array_keys($value)) > 0 && $count !== 1;
                })
                ->thenInvalid('Only one connection can be default.')
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
                    ->scalarNode('username')->isRequired()->end()
                    ->scalarNode('password')->isRequired()->end()
                    ->scalarNode('url')->cannotBeEmpty()->defaultValue('https://login.salesforce.com')->end()
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
                        ->scalarNode('query')->end()
                        ->arrayNode('filter')
                            ->prototype('scalar')->end()
                        ->end()
                        ->scalarNode('api_version')->defaultValue('43.0')->end()
                        ->booleanNode('create_if_not_exists')->defaultTrue()->end()
                        ->booleanNode('create')->defaultTrue()->end()
                        ->booleanNode('update')->defaultTrue()->end()
                        ->booleanNode('undelete')->defaultTrue()->end()
                        ->booleanNode('delete')->defaultTrue()->end()
                        ->scalarNode('notify_for_fields')
                            ->defaultValue('Referenced')
                            ->validate()
                                ->ifNotInArray(['All', 'Referenced', 'Select', 'Where'])
                                ->thenInvalid('Invalid value for notify for fields.')
                            ->end()
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
