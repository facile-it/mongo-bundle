<?php

declare(strict_types=1);

namespace Facile\MongoDbBundle\DependencyInjection;

use Facile\MongoDbBundle\Event\ConnectionEvent;
use Facile\MongoDbBundle\Event\QueryEvent;
use Facile\MongoDbBundle\Services\ClientRegistry;
use Facile\MongoDbBundle\Services\ConnectionFactory;
use MongoDB\Database;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * @internal
 */
final class MongoDbBundleExtension extends Extension
{
    private ?ContainerBuilder $containerBuilder = null;

    public function load(array $configs, ContainerBuilder $container): void
    {
        $this->containerBuilder = $container;
        $config = $this->processConfiguration(new Configuration(), $configs);

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.xml');

        $this->defineClientRegistry($config, $container->getParameter('kernel.debug'));
        $this->defineConnectionFactory();
        $this->defineConnections($config['connections']);

        if ($this->mustCollectData($config)) {
            $loader->load('profiler.xml');
            $this->attachDataCollectionListenerToEventManager();
        }
    }

    private function mustCollectData(array $config): bool
    {
        return true === $this->containerBuilder->getParameter('kernel.debug')
            && class_exists(WebProfilerBundle::class)
            && $config['data_collection'] === true;
    }

    private function defineClientRegistry(array $config, bool $debug): void
    {
        $clientsConfig = $config['clients'];
        $clientRegistryDefinition = new Definition(
            ClientRegistry::class,
            [
                new Reference('facile_mongo_db.event_dispatcher'),
                $debug,
                $this->defineUriOptionsFactory($config),
                $this->defineDriverOptionsFactory($config),
            ]
        );
        $clientRegistryDefinition->addMethodCall('addClientsConfigurations', [$clientsConfig]);
        $clientRegistryDefinition->setPublic(true);

        $this->containerBuilder->setDefinition('mongo.client_registry', $clientRegistryDefinition);
    }

    private function defineConnectionFactory(): void
    {
        $factoryDefinition = new Definition(ConnectionFactory::class, [new Reference('mongo.client_registry')]);
        $factoryDefinition->setPublic(false);

        $this->containerBuilder->setDefinition('mongo.connection_factory', $factoryDefinition);
    }

    private function defineConnections(array $connections): void
    {
        foreach ($connections as $name => $conf) {
            $connectionDefinition = new Definition(
                Database::class,
                [
                    $conf['client_name'],
                    $conf['database_name'],
                ]
            );
            $connectionDefinition->setFactory([new Reference('mongo.connection_factory'), 'createConnection']);
            $connectionDefinition->setPublic(true);
            $this->containerBuilder->setDefinition('mongo.connection.' . $name, $connectionDefinition);
        }
        $this->containerBuilder->setAlias('mongo.connection', new Alias('mongo.connection.' . array_keys($connections)[0], true));
    }

    private function attachDataCollectionListenerToEventManager(): void
    {
        $eventManagerDefinition = $this->containerBuilder->getDefinition('facile_mongo_db.event_dispatcher');
        $eventManagerDefinition->addMethodCall(
            'addListener',
            [
                ConnectionEvent::CLIENT_CREATED,
                [new Reference('facile_mongo_db.data_collector.listener'), 'onConnectionClientCreated'],
            ]
        );
        $eventManagerDefinition->addMethodCall(
            'addListener',
            [
                QueryEvent::QUERY_EXECUTED,
                [new Reference('facile_mongo_db.data_collector.listener'), 'onQueryExecuted'],
            ]
        );
    }

    private function defineUriOptionsFactory(array $config): ?Reference
    {
        return isset($config['uriOptions']) ? new Reference($config['uriOptions']) : null;
    }

    private function defineDriverOptionsFactory(array $config): ?Reference
    {
        return isset($config['driverOptions']) ? new Reference($config['driverOptions']) : null;
    }
}
