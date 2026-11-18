<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Tests\Calendar;

use Doctrine\DBAL\DriverManager;
use Koertho\ContaoChurchTools\Calendar\Backend\CalendarAssignment;
use Koertho\ContaoChurchTools\Calendar\Backend\EventProtection;
use PHPUnit\Framework\TestCase;

final class BackendCalendarAssignmentTest extends TestCase
{
    public function testOptionalAssignmentRejectsAnotherArchiveAndAllowsOwnSelection(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE tl_calendar (id INTEGER PRIMARY KEY,ct_calendar_id INTEGER UNIQUE)');
        $db->insert('tl_calendar', ['id' => 1, 'ct_calendar_id' => 110]);
        $assignment = new CalendarAssignment($db);
        self::assertNull($assignment->save('', 1));
        self::assertSame(110, $assignment->save('110', 1));
        self::assertSame(112, $assignment->save('112', 2));
        $this->expectException(\InvalidArgumentException::class);
        $assignment->save('110', 2);
    }

    public function testMalformedCalendarIdsAreRejected(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $assignment = new CalendarAssignment($db);
        foreach (['-1', '0', '01', '2e2', '4294967296', [], false] as $input) {
            try {
                $assignment->save($input, 1);
                self::fail('Malformed calendar ID was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testNativeSubmitCannotReinterpretProtectedInstants(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE tl_calendar_events (id INTEGER PRIMARY KEY,ct_sync_key TEXT,title TEXT,startTime INTEGER,endTime INTEGER,teaser TEXT)');
        $db->insert('tl_calendar_events', ['id' => 1, 'ct_sync_key' => 'key', 'title' => 'Original', 'startTime' => 1792888200, 'endTime' => 1792891800, 'teaser' => 'Old']);
        $protection = new EventProtection($db);
        $protection->record(1);
        $db->update('tl_calendar_events', ['startTime' => 1792891800, 'teaser' => 'Editorial change'], ['id' => 1]);
        $protection->restore(1);
        self::assertSame(1792888200, (int) $db->fetchOne('SELECT startTime FROM tl_calendar_events WHERE id=1'));
        self::assertSame('Editorial change', $db->fetchOne('SELECT teaser FROM tl_calendar_events WHERE id=1'));
    }
}
