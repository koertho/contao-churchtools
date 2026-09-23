<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\EventListener\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Koertho\ChurchToolsBundle\Sync\Synchronizer;
use Psr\Log\LoggerInterface;

#[AsCronJob('hourly')]
final class SyncListener
{
    public function __construct(private readonly Synchronizer $synchronizer, private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(): void
    {
        try {
            foreach ($this->synchronizer->synchronize() as $id => $result) {
                $this->logger->log($result['status'] === 'success' ? 'info' : 'warning', 'ChurchTools synchronization', ['archive' => $id] + $result);
            }
        } catch (\Throwable) {
            $this->logger->error('ChurchTools synchronization could not start. Check local configuration and database access.');
        }
    }
}
