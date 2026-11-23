<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Calendar\Backend;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/** Only successful explicit publication changes may override sync visibility. */
final class PublicationChanges implements ResetInterface
{
    private array $pending = [];

    public function __construct(private readonly Connection $connection) {}

    public function prepare(int $id, mixed $value): void
    {
        $current = $this->connection->fetchAssociative('SELECT published,ct_sync_key FROM tl_calendar_events WHERE id=?', [$id]);
        if ($current && $current['ct_sync_key'] !== null) {
            $this->pending[$id] = ['before' => (bool) $current['published'], 'after' => (bool) $value];
        }
    }

    public function commit(int $id): void
    {
        $change = $this->pending[$id] ?? null;
        unset($this->pending[$id]);
        if ($change === null || $change['before'] === $change['after']) {
            return;
        }
        $this->connection->executeStatement("UPDATE tl_calendar_events SET ct_unpublished_by_sync='' WHERE id=? AND ct_sync_key IS NOT NULL AND published=?", [$id, (int) $change['after']]);
    }

    public function reset(): void
    {
        $this->pending = [];
    }
}
