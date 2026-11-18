<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Calendar\Backend;

use Doctrine\DBAL\Connection;

final class CalendarAssignment
{
    public function __construct(private readonly Connection $connection) {}

    public function save(mixed $value, int $archiveId): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ((!is_int($value) && (!is_string($value) || !preg_match('/^[1-9][0-9]{0,9}$/D', $value)))
            || (int) $value < 1 || (int) $value > 4294967295) {
            throw new \InvalidArgumentException('Select a valid ChurchTools calendar.');
        }
        $id = (int) $value;
        if ($this->connection->fetchOne('SELECT id FROM tl_calendar WHERE ct_calendar_id=? AND id<>?', [$id, $archiveId]) !== false) {
            throw new \InvalidArgumentException('This ChurchTools calendar is already assigned to another calendar archive.');
        }

        return $id;
    }
}
