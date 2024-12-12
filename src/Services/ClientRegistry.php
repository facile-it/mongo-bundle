<?php

declare(strict_types=1);

namespace Facile\MongoDbBundle\Services;

use Facile\MongoDbBundle\Capsule\Client as BundleClient;
use Facile\MongoDbBundle\Event\ConnectionEvent;
use Facile\MongoDbBundle\Models\ClientConfiguration;
use Facile\MongoDbBundle\Services\DriverOptions\DriverOptionsInterface;
use Facile\MongoDbBundle\Services\UriOptions\UriOptionsInterface;
use MongoDB\Client;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Class ClientRegistry.
 *
 * @internal
 */
final class ClientRegistry
{
    /** @var Client[] */
    private array $clients = [];

    /** @var ClientConfiguration[] */
    private array $configurations = [];

    private bool $debug;

    private EventDispatcherInterface $eventDispatcher;

    private ?UriOptionsInterface $uriOptionsService;

    private ?DriverOptionsInterface $driverOptionsService;

    public function __construct(
        EventDispatcherInterface $eventDispatcher,
        bool $debug,
        ?UriOptionsInterface $uriOptionsService,
        ?DriverOptionsInterface $driverOptionsService
    ) {
        $this->debug = $debug;
        $this->eventDispatcher = $eventDispatcher;
        $this->driverOptionsService = $driverOptionsService;
        $this->uriOptionsService = $uriOptionsService;
    }

    public function addClientsConfigurations(array $configurations): void
    {
        foreach ($configurations as $name => $conf) {
            $this->configurations[$name] = $this->buildClientConfiguration($conf);
        }
    }

    private function buildClientConfiguration(array $conf): ClientConfiguration
    {
        if (! $conf['uri']) {
            $conf['uri'] = $this->buildConnectionUri($conf['hosts']);
        }

        $conf['uriOptions'] = [
            'replicaSet' => $conf['replicaSet'],
            'ssl' => $conf['ssl'],
            'connectTimeoutMS' => $conf['connectTimeoutMS'],
            'readPreference' => $conf['readPreference'],
        ];
        if ($this->uriOptionsService instanceof UriOptionsInterface) {
            $conf['uriOptions'] = $this->uriOptionsService->buildUriOptions($conf['uriOptions']);
        }

        $conf['driverOptions'] = [];
        if ($this->driverOptionsService instanceof DriverOptionsInterface) {
            $conf['driverOptions'] = $this->driverOptionsService->buildDriverOptions($conf);
        }

        return new ClientConfiguration(
            $conf['uri'],
            $conf['username'],
            $conf['password'],
            $conf['authSource'],
            $conf['uriOptions'],
            $conf['driverOptions']
        );
    }

    private function buildConnectionUri(array $hosts): string
    {
        return 'mongodb://' . implode(
            ',',
            array_map(
                static fn(array $host): string => sprintf('%s:%d', $host['host'], $host['port']),
                $hosts
            )
        );
    }

    public function getClientForDatabase(string $name, string $databaseName): Client
    {
        return $this->getClient($name, $databaseName);
    }

    public function getClientNames(): array
    {
        return array_keys($this->clients);
    }

    public function getClient(string $name, ?string $databaseName = null): Client
    {
        $clientKey = null !== $databaseName ? $name . '.' . $databaseName : $name;

        if (! isset($this->clients[$clientKey])) {
            $conf = $this->configurations[$name];
            $options = array_merge(
                ['database' => $databaseName],
                $conf->getAuthSource() !== null ? ['authSource' => $conf->getAuthSource()] : [],
                $conf->getOptions()
            );
            $this->clients[$clientKey] = $this->buildClient($name, $conf->getUri(), $options, $conf->getDriverOptions());

            $event = new ConnectionEvent($clientKey);
            $this->eventDispatcher->dispatch($event, ConnectionEvent::CLIENT_CREATED);
        }

        return $this->clients[$clientKey];
    }

    private function buildClient(string $clientName, string $uri, array $options, array $driverOptions): Client
    {
        if ($this->debug) {
            return new BundleClient($uri, $options, $driverOptions, $clientName, $this->eventDispatcher);
        }

        return new Client($uri, $options, $driverOptions);
    }

    /**
     * @return ClientConfiguration[]
     */
    public function getConfigurations(): array
    {
        return $this->configurations;
    }
}
