<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Tests\Calendar;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Koertho\ContaoChurchTools\Calendar\Backend\EventProtection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class BackendSyncLockTest extends TestCase
{
    public function testSourceSnapshotKeepsSynchronizerOutUntilRequestIsFinished(): void
    {
        $url = getenv('CHURCHTOOLS_TEST_DATABASE_URL') ?: '';
        if (!preg_match('~/churchtools_v2_[a-z0-9_]+(?:\?|$)~D', $url)) {
            self::markTestSkipped('Dedicated churchtools_v2_* database required.');
        }
        $parameters = (new DsnParser(['mysql' => 'pdo_mysql']))->parse($url);
        $editor = DriverManager::getConnection($parameters);
        $worker = DriverManager::getConnection($parameters);
        $editor->executeStatement('CREATE TEMPORARY TABLE tl_calendar_events (id INT PRIMARY KEY, pid INT UNSIGNED, ct_sync_key VARCHAR(64), title VARCHAR(255))');
        $editor->insert('tl_calendar_events', ['id' => 1, 'pid' => 4100000011, 'ct_sync_key' => 'source', 'title' => 'Latest title']);
        $requests = new RequestStack();
        $requests->push(Request::create('/contao', 'POST'));
        $protection = new EventProtection($editor, $requests);
        $lock = 'ct-calendar:'.substr(hash('sha256', $editor->getDatabase().':4100000011'), 0, 48);
        try {
            self::assertSame('Latest title', $protection->record(1)['title']);
            self::assertSame(0, (int) $worker->fetchOne('SELECT GET_LOCK(?,0)', [$lock]), 'Sync must not race the native date normalization and source restoration.');
            $protection->reset();
            self::assertSame(1, (int) $worker->fetchOne('SELECT GET_LOCK(?,0)', [$lock]));
        } finally {
            $protection->reset();
            $worker->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
            $editor->close();
            $worker->close();
        }
    }
}
