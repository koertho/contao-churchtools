<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\EventListener\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Koertho\ContaoChurchTools\Calendar\Sync\CalendarEventSynchronizer;
use Psr\Log\LoggerInterface;

#[AsCronJob('*/15 * * * *')]
final class SyncListener
{
    public function __construct(private readonly CalendarEventSynchronizer $synchronizer, private readonly LoggerInterface $logger)
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
