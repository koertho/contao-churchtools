<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools;

use Koertho\ContaoChurchTools\Api\ChurchToolsClient;
use Koertho\ContaoChurchTools\Api\Transport;
use Koertho\ContaoChurchTools\Command\SetupTokenCommand;
use Koertho\ContaoChurchTools\Calendar\Command\SyncCalendarEventsCommand;
use Koertho\ContaoChurchTools\EventListener\Cron\SyncListener;
use Koertho\ContaoChurchTools\Calendar\Sync\SnapshotFetcher;
use Koertho\ContaoChurchTools\Calendar\Sync\CalendarEventSynchronizer;
use Koertho\ContaoChurchTools\Setup\TokenSetup;
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
        $services->load('Koertho\\ContaoChurchTools\\Calendar\\Sync\\', __DIR__.'/Calendar/Sync/')->exclude([__DIR__.'/Calendar/Sync/EventFields.php', __DIR__.'/Calendar/Sync/SourceOccurrence.php', __DIR__.'/Calendar/Sync/SyncWindow.php']);
        $services->set(CalendarEventSynchronizer::class)
            ->arg('$slug', service('contao.slug'))
            ->arg('$pastMonths', $config['sync']['past_months'])
            ->arg('$futureMonths', $config['sync']['future_months']);
        $services->set(SyncCalendarEventsCommand::class);
        $services->set(SyncListener::class);
        $services->load('Koertho\\ContaoChurchTools\\Calendar\\Backend\\', __DIR__.'/Calendar/Backend/');
        $services->load('Koertho\\ContaoChurchTools\\Calendar\\Security\\', __DIR__.'/Calendar/Security/');
        $services->load('Koertho\\ContaoChurchTools\\Calendar\\Frontend\\', __DIR__.'/Calendar/Frontend/');
        $services->load('Koertho\\ContaoChurchTools\\EventListener\\Frontend\\', __DIR__.'/EventListener/Frontend/');
        $services->load('Koertho\\ContaoChurchTools\\EventListener\\DataContainer\\', __DIR__.'/EventListener/DataContainer/');
    }
}
