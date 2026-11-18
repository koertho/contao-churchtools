<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Tests\Calendar;

use Doctrine\DBAL\DriverManager;
use Koertho\ContaoChurchTools\Calendar\Backend\PublicationChanges;
use PHPUnit\Framework\TestCase;

final class BackendPublicationTest extends TestCase
{
    public function testUnchangedOrInvalidPublicationSavePreservesSyncFlag(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE tl_calendar_events (id INTEGER PRIMARY KEY,published INTEGER,ct_sync_key TEXT,ct_unpublished_by_sync TEXT)');
        $db->insert('tl_calendar_events', ['id' => 1, 'published' => 0, 'ct_sync_key' => 'key', 'ct_unpublished_by_sync' => '1']);
        $changes = new PublicationChanges($db);
        $changes->prepare(1, '');
        $changes->commit(1);
        self::assertSame('1', $db->fetchOne('SELECT ct_unpublished_by_sync FROM tl_calendar_events WHERE id=1'));
        $changes->reset();
        $changes->prepare(1, '1');
        // A failed native form has not persisted the requested publication or invoked onsubmit.
        self::assertSame('1', $db->fetchOne('SELECT ct_unpublished_by_sync FROM tl_calendar_events WHERE id=1'));
        // Even invoking commit before the value is persisted must not clear the marker.
        $changes->commit(1);
        self::assertSame('1', $db->fetchOne('SELECT ct_unpublished_by_sync FROM tl_calendar_events WHERE id=1'));
    }

    public function testCommittedExplicitPublicationChangeClearsSyncFlag(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE tl_calendar_events (id INTEGER PRIMARY KEY,published INTEGER,ct_sync_key TEXT,ct_unpublished_by_sync TEXT)');
        $db->insert('tl_calendar_events', ['id' => 1, 'published' => 0, 'ct_sync_key' => 'key', 'ct_unpublished_by_sync' => '1']);
        $changes = new PublicationChanges($db);
        $changes->prepare(1, '1');
        $db->update('tl_calendar_events', ['published' => 1], ['id' => 1]);
        $changes->commit(1);
        self::assertSame('', $db->fetchOne('SELECT ct_unpublished_by_sync FROM tl_calendar_events WHERE id=1'));
    }
}
