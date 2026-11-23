<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Tests\Calendar;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Tools\DsnParser;
use Koertho\ContaoChurchTools\Calendar\Backend\SeriesDeletionProtection;
use PHPUnit\Framework\TestCase;

final class SeriesDeletionLockTest extends TestCase
{
    public function testOrphanDeletionKeepsSynchronizerOutUntilDeletionFinishes(): void
    {
        $url = getenv('CHURCHTOOLS_TEST_DATABASE_URL') ?: '';
        if (!preg_match('~/churchtools_v2_[a-z0-9_]+(?:\?|$)~D', $url)) self::markTestSkipped('Dedicated test database required.');
        $parameters = (new DsnParser(['mysql' => 'pdo_mysql']))->parse($url);
        $editor = DriverManager::getConnection($parameters);
        $worker = DriverManager::getConnection($parameters);
        if (!$editor->createSchemaManager()->tablesExist(['tl_ct_series_templates', 'tl_calendar_events'])) {
            self::markTestSkipped('Run isolated schema setup first.');
        }
        $editor->insert('tl_ct_series_templates', ['ct_appointment_id' => 4100000041, 'title' => 'Deletion lock fixture', 'tstamp' => time()]);
        $id = (int) $editor->lastInsertId();
        $protection = null;
        try {
            $protection = new SeriesDeletionProtection($editor);
            $protection->protect($id);
            $worker->beginTransaction();
            try {
                $worker->fetchAssociative('SELECT id FROM tl_ct_series_templates WHERE id=? FOR UPDATE NOWAIT', [$id]);
                self::fail('Sync could relink an orphan while its content is being deleted.');
            } catch (LockWaitTimeoutException) {
                self::assertTrue($editor->isTransactionActive());
            } finally {
                $worker->rollBack();
            }
            $protection->reset();
            self::assertSame($id, (int) $worker->fetchOne('SELECT id FROM tl_ct_series_templates WHERE id=?', [$id]));
        } finally {
            $protection?->reset();
            $editor->delete('tl_ct_series_templates', ['id' => $id]);
            $editor->close();
            $worker->close();
        }
    }
}
