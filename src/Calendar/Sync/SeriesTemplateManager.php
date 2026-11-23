<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Calendar\Sync;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/** Must be called within the archive transaction. Editorial fields are never written. */
final class SeriesTemplateManager
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array{0: ?int, 1: list<int>} Template ID and actually changed template IDs. */
    public function synchronize(array $mapped, ?int $existingSeriesId, int $now): array
    {
        if ($mapped['_repeatId'] === 0) {
            if ($existingSeriesId !== null && $this->connection->fetchOne('SELECT id FROM tl_ct_series_templates WHERE id=? FOR UPDATE', [$existingSeriesId]) === false) {
                throw new \RuntimeException('Referenced series template no longer exists.');
            }
            return [$existingSeriesId, []];
        }
        $db = $this->connection;
        $row = $db->fetchAssociative('SELECT id,title FROM tl_ct_series_templates WHERE ct_appointment_id=? FOR UPDATE', [$mapped['ct_appointment_id']]);
        if (!$row) {
            // A calendar move can make the same series visible to another archive worker.
            try {
                $db->insert('tl_ct_series_templates', ['ct_appointment_id' => $mapped['ct_appointment_id'], 'title' => $mapped['title'], 'tstamp' => $now]);
                $created = true;
            } catch (UniqueConstraintViolationException) {
                $created = false;
            }
            $row = $db->fetchAssociative('SELECT id,title FROM tl_ct_series_templates WHERE ct_appointment_id=? FOR UPDATE', [$mapped['ct_appointment_id']]);
            if (!$row) {
                throw new \RuntimeException('Series template could not be stored.');
            }
            if ($created) {
                return [(int) $row['id'], [(int) $row['id']]];
            }
        }
        $id = (int) $row['id'];
        if ($row['title'] !== $mapped['title']) {
            $db->update('tl_ct_series_templates', ['title' => $mapped['title'], 'tstamp' => $now], ['id' => $id]);

            return [$id, [$id]];
        }

        return [$id, []];
    }
}
