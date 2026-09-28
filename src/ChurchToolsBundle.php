<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle;

use Koertho\ChurchToolsBundle\Api\ChurchToolsClient;
use Koertho\ChurchToolsBundle\Api\Transport;
use Koertho\ChurchToolsBundle\Command\SetupTokenCommand;
use Koertho\ChurchToolsBundle\Command\SyncCommand;
use Koertho\ChurchToolsBundle\EventListener\Cron\SyncListener;
use Koertho\ChurchToolsBundle\Sync\SnapshotFetcher;
use Koertho\ChurchToolsBundle\Sync\Synchronizer;
use Koertho\ChurchToolsBundle\Setup\TokenSetup;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class ChurchToolsBundle extends AbstractBundle
{
    protected string $extensionAlias = 'church_tools';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()->children()
            ->stringNode('instance_url')->defaultValue('')->end()
            ->stringNode('token')->defaultValue('')->end()
            ->arrayNode('setup')->addDefaultsIfNotSet()->children()
                ->stringNode('username')->defaultValue('')->end()
                ->stringNode('password')->defaultValue('')->end()
            ->end()->end()
            ->arrayNode('sync')->addDefaultsIfNotSet()->children()
                ->integerNode('future_months')->min(1)->max(120)->defaultValue(6)->end()
                ->integerNode('past_months')->min(0)->max(120)->defaultValue(1)->end()
            ->end()->end()
        ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->setParameter('church_tools.sync.future_months', $config['sync']['future_months']);
        $builder->setParameter('church_tools.sync.past_months', $config['sync']['past_months']);
        $services = $container->services()->defaults()->autowire()->autoconfigure();
        // Dedicated, untraced client: credentials/private payloads must not enter the profiler.
        $services->set('church_tools.http_client')->class(\Symfony\Contracts\HttpClient\HttpClientInterface::class)
            ->factory([HttpClient::class, 'create']);
        $services->set(Transport::class)->args([service('church_tools.http_client'), $config['instance_url']]);
        $services->set(ChurchToolsClient::class)->args([service(Transport::class), $config['token']]);
        $services->set(TokenSetup::class)->args([service(Transport::class), $config['setup']['username'], $config['setup']['password']]);
        $services->set(SetupTokenCommand::class);
        $services->set(SnapshotFetcher::class);
        $services->set(Synchronizer::class)
            ->arg('$pastMonths', $config['sync']['past_months'])
            ->arg('$futureMonths', $config['sync']['future_months']);
        $services->set(SyncCommand::class);
        $services->set(SyncListener::class);
        $services->load('Koertho\\ChurchToolsBundle\\Backend\\', __DIR__.'/Backend/');
        $services->load('Koertho\\ChurchToolsBundle\\EventIntegration\\', __DIR__.'/EventIntegration/');
        $services->load('Koertho\\ChurchToolsBundle\\Controller\\', __DIR__.'/Controller/')->tag('controller.service_arguments');
        $services->load('Koertho\\ChurchToolsBundle\\Frontend\\', __DIR__.'/Frontend/');
        $services->load('Koertho\\ChurchToolsBundle\\EventListener\\Frontend\\', __DIR__.'/EventListener/Frontend/');
        $services->load('Koertho\\ChurchToolsBundle\\EventListener\\DataContainer\\', __DIR__.'/EventListener/DataContainer/');
    }
}
