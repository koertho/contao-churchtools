<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Calendar\Backend;

use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/** Preserve source instants even when the native onsubmit callback reparses a DST clock. */
final class EventProtection implements ResetInterface
{
    public const SOURCE_FIELDS = ['pid', 'title', 'addTime', 'startDate', 'endDate', 'startTime', 'endTime', 'location', 'address', 'recurring', 'repeatEach', 'recurrences', 'repeatEnd', 'source'];
    public const SYNC_FIELDS = ['ct_sync_key', 'ct_calendar_id', 'ct_appointment_id', 'ct_series_template_id', 'ct_hash', 'ct_last_sync', 'ct_unpublished_by_sync', 'ct_description'];

    private array $original = [];
    private array $locks = [];

    public function __construct(private readonly Connection $connection, private readonly ?RequestStack $requests = null) {}

    public function record(int $id): ?array
    {
        if (!array_key_exists($id, $this->original)) {
            $row = $this->connection->fetchAssociative('SELECT * FROM tl_calendar_events WHERE id=?', [$id]) ?: null;
            if (($row['ct_sync_key'] ?? null) !== null && $this->requests?->getCurrentRequest()?->isMethod('POST')) {
                $lock = 'ct-calendar:'.substr(hash('sha256', $this->connection->getDatabase().':'.$row['pid']), 0, 48);
                if (!isset($this->locks[$lock])) {
                    if ((int) $this->connection->fetchOne('SELECT GET_LOCK(?,0)', [$lock]) !== 1) {
                        throw new ConflictHttpException('ChurchTools synchronization is running. Retry saving this event.');
                    }
                    $this->locks[$lock] = true;
                }
                // The native onsubmit normalizer must not race a source write. Read
                // again only after acquiring the same archive lock as the sync.
                $row = $this->connection->fetchAssociative('SELECT * FROM tl_calendar_events WHERE id=?', [$id]) ?: null;
            }
            $this->original[$id] = $row;
        }

        return $this->original[$id];
    }

    public function restore(int $id): void
    {
        $row = $this->original[$id] ?? null;
        if ($row !== null && $row['ct_sync_key'] !== null) {
            $this->connection->update('tl_calendar_events', array_intersect_key($row, array_flip(self::SOURCE_FIELDS)), ['id' => $id]);
        }
    }

    public function reset(): void
    {
        foreach (array_keys($this->locks) as $lock) {
            if ($this->connection->isConnected()) {
                $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
            }
        }
        $this->locks = [];
        $this->original = [];
    }

    #[AsEventListener(event: KernelEvents::FINISH_REQUEST)]
    public function finishRequest(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->reset();
        }
    }
}
