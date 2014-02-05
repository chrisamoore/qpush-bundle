<?php
namespace Uecode\Bundle\HateoasBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode    = $treeBuilder->root('uecode_qpush');

        $rootNode
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('aws_credentials')
                    ->scalarNode('api_key')
                        ->isRequired()
                    ->end()
                    ->scalarNode('api_token')
                        ->isRequired()
                    ->end()
                    ->scalarNode('region')
                        ->isRequired()
                    ->end()
                ->end()
                ->scalarNode('cache_service_id')
                    ->defaultNull()
                ->end()
                ->arrayNode('queues')
                    ->isRequired()
                    ->requiresAtLeastOneElement()
                    ->useAttributeAsKey('name')
                    ->prototype('array')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('delay_seconds')
                            ->defaultValue(0)                
                            ->info('An integer from 0 to 900 (15 minutes)')
                            ->example(0)
                        ->end()
                        ->scalarNode('max_message_size')
                            ->defaultValue(262144)
                            ->info('An integer from 1024 up to 262144 in bytes')
                            ->example(1024)
                        ->end()
                        ->scalarNode('message_retention')
                            ->defaultValue(345600)
                            ->info('An integer representing seconds, from 60 to 1209600 (14 days)')
                            ->example(345600)
                        ->end()
                        ->scalarNode('visibility_timeout')
                            ->defaultValue(30)
                            ->info('An integer representing seconds, from 0 to 43200 (12 hours)')
                            ->example(30)
                        ->end()
                        ->scalarNode('use_sns')
                            ->isRequired()
                            ->defaultFalse()
                            ->info('Whether or not to use SNS to push queue notifications')
                        ->end()
                        ->arrayNode('subscribers')
                            ->useAttributeAsKey('name')
                            ->prototype('array')
                            ->children()
                                ->scalarNode('endpoint')
                                    ->info('The url or email address to notify')
                                    ->example('http://foo.bar/qpush/')
                                ->end()
                                ->enumNode('protocol')
                                    ->values(['email', 'http', 'https'])
                                    ->info('The endpoint type')
                                    ->example('http')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}