<?php

namespace Uecode\Bundle\QPushBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;

class QPushCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        $cache      = $container->getParameter('uecode_qpush.cache');
        $queues     = $container->getParameter('uecode_qpush.queues');
        $providers  = $container->getParameter('uecode_qpush.providers');

        foreach ($queues as $queue => $options) {
            $name = sprintf('uecode_qpush.%s', $queue);

            $definition = $container->getDefinition($name);

            if ($cache = $this->getCache($cache, $container)) {
                $definition->replaceArgument(2, $cache);
            }

            $provider = $options['provider'];
            if (isset($providers[$provider]['provider_service'])) {
                $service = $providers[$provider]['provider_service'];
                if (!$container->hasDefinition($service)) {
                    throw new \InvalidArgumentException(
                        sprintf("The service \"%s\" does not exist.", $service)
                    );
                }
                $definition->addMethodCall('setService', [new Reference($service)]);
            }
        }
    }

    /**
     * @param string           $cache     Optional Cache Service Id
     * @param ContainerBuilder $container Container from Symfony
     *
     * @return Reference|Definition
     */
    private function getCache($cache, ContainerBuilder $container)
    {
        if (null !== $cache) {
            if (!$container->hasDefinition($cache)) {
                throw new \InvalidArgumentException(
                    sprintf("The service \"%s\" does not exist.", $cache)
                );
            }

            return new Reference($cache);
        }

        return false;
    }
}
