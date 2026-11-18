<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Tests\Calendar\Sync;

use Contao\CoreBundle\Cache\CacheTagManager;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Slug\Slug;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Koertho\ContaoChurchTools\Calendar\Sync\CalendarEventMapper;
use Koertho\ContaoChurchTools\Calendar\Sync\CalendarEventSynchronizer;
use Koertho\ContaoChurchTools\Calendar\Sync\SeriesTemplateManager;
use Koertho\ContaoChurchTools\Calendar\Sync\SnapshotFetcher;
use Koertho\ContaoChurchTools\Calendar\Image\CachedImageStoreInterface;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/RemoteFixture.php';

/** SQL runs only against connection-local temporary tables in a dedicated test DB. */
final class SynchronizerTest extends TestCase
{
    private Connection $db;
    private RemoteFixture $remote;
    private CalendarEventSynchronizer $sync;
    private array $invalidated = [];
    private string $previousTimezone;
    private bool $cacheFailure = false;
    private bool $slugFailure = false;
    private array $warnings = [];
    private array $imageCalls = [];
    private bool $imageFailure = false;
    private string $imageUuid = '1234567890123456';

    protected function setUp(): void
    {
        $this->previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
        $url = getenv('CHURCHTOOLS_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Requires an isolated CHURCHTOOLS_TEST_DATABASE_URL.');
        }
        $params = (new DsnParser(['mysql' => 'pdo_mysql']))->parse($url);
        if (!str_starts_with($params['dbname'] ?? '', 'churchtools_')) {
            throw new \RuntimeException('Only dedicated churchtools_ test databases are allowed.');
        }
        $this->db = DriverManager::getConnection($params);
        foreach ([
            'CREATE TEMPORARY TABLE tl_calendar (id INT UNSIGNED PRIMARY KEY, ct_calendar_id INT UNSIGNED NULL, ct_last_sync INT NULL, ct_sync_error TEXT NULL, ct_sync_counts TEXT NULL, jumpTo INT NOT NULL DEFAULT 0)',
            "CREATE TEMPORARY TABLE tl_calendar_events (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,pid INT UNSIGNED NOT NULL,tstamp INT NOT NULL DEFAULT 0,title VARCHAR(255) NOT NULL DEFAULT '',alias VARCHAR(255) NOT NULL DEFAULT '',published TINYINT NOT NULL DEFAULT 0,source VARCHAR(12) NOT NULL DEFAULT '',recurring TINYINT NOT NULL DEFAULT 0,addTime TINYINT NOT NULL DEFAULT 0,startDate BIGINT NOT NULL DEFAULT 0,endDate BIGINT NOT NULL DEFAULT 0,startTime BIGINT NOT NULL DEFAULT 0,endTime BIGINT NOT NULL DEFAULT 0,location TEXT NULL,address TEXT NULL,teaser TEXT NULL,ct_sync_key VARCHAR(64) NULL,ct_calendar_id INT NULL,ct_appointment_id INT NULL,ct_series_template_id INT NULL,ct_hash VARCHAR(64) NULL,ct_description TEXT NULL,ct_image_src BINARY(16) NULL,ct_image_key VARCHAR(64) NULL,ct_last_sync INT NULL,ct_unpublished_by_sync CHAR(1) NOT NULL DEFAULT '',UNIQUE KEY sync_key(pid,ct_sync_key))",
            "CREATE TEMPORARY TABLE tl_ct_series_templates (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tstamp INT NOT NULL DEFAULT 0,ct_appointment_id INT UNSIGNED UNIQUE,title VARCHAR(255) NOT NULL DEFAULT '',teaser TEXT NULL)",
            "CREATE TEMPORARY TABLE tl_content (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,pid INT UNSIGNED NOT NULL,ptable VARCHAR(64) NOT NULL DEFAULT '')",
        ] as $sql) {
            $this->db->executeStatement($sql);
        }
        $this->db->insert('tl_calendar', ['id' => 1, 'ct_calendar_id' => 110]);
        $this->remote = new RemoteFixture();
        $this->remote->rows = [RemoteFixture::row('public')];
        $framework = $this->createStub(ContaoFramework::class);
        $pages = $this->createStub(Adapter::class);
        $pages->method('__call')->willReturnCallback(static function (string $method, array $arguments): ?object {
            if ($method !== 'findWithDetails') throw new \LogicException('Unexpected page lookup.');
            return $arguments === [91] ? (object) ['rootId' => 42] : null;
        });
        $framework->method('getAdapter')->willReturn($pages);
        $cache = $this->createStub(CacheTagManager::class);
        $cache->method('invalidateTags')->willReturnCallback(function (array $tags) use ($cache): CacheTagManager { if ($this->cacheFailure) throw new \RuntimeException('synthetic cache failure'); $this->invalidated = array_merge($this->invalidated, $tags); return $cache; });
        $slug = $this->createStub(Slug::class);
        $slug->method('generate')->willReturnCallback(function (string $title): string { if ($this->slugFailure) throw new \RuntimeException('synthetic slug failure'); return strtolower(str_replace(' ', '-', $title)); });
        $logger = new class(function (array $context): void { $this->warnings[] = $context; }) extends \Psr\Log\AbstractLogger {
            public function __construct(private readonly \Closure $capture) {}
            public function log($level, string|\Stringable $message, array $context = []): void { ($this->capture)($context); }
        };
        $images = $this->createStub(CachedImageStoreInterface::class);
        $images->method('obtain')->willReturnCallback(function (array $image): string {
            $this->imageCalls[] = $image;
            if ($this->imageFailure) throw new \RuntimeException('private diagnostic');
            return $this->imageUuid;
        });
        $this->sync = new CalendarEventSynchronizer(new SnapshotFetcher($this->remote->client()), new CalendarEventMapper(), new SeriesTemplateManager($this->db), $this->db, $framework, $cache, $logger, $slug, images: $images, imagePath: 'churchtools-import/{year}/{month}/{day}/{alias}');
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) $this->db->close();
        if (isset($this->previousTimezone)) date_default_timezone_set($this->previousTimezone);
    }

    private function runSync(): array
    {
        return $this->sync->synchronize(1, new \DateTimeImmutable('2026-09-17T12:00:00Z'))[1];
    }

    private function event(): array
    {
        return $this->db->fetchAssociative('SELECT * FROM tl_calendar_events ORDER BY id LIMIT 1');
    }

    public function testImageChangesAndFailuresPreserveEditorialState(): void
    {
        $this->remote->rows[0]['appointment']['base']['image'] = ['id' => 7, 'imageUrl' => 'https://example.org/images/7/photo'];
        self::assertSame('success', $this->runSync()['status']);
        $first = $this->event();
        self::assertSame($this->imageUuid, $first['ct_image_src']);
        $this->db->update('tl_calendar_events', ['published' => 0, 'teaser' => 'Editorial', 'tstamp' => 42], ['id' => $first['id']]);
        $this->invalidated = [];
        self::assertSame('success', $this->runSync()['status']);
        self::assertSame([], $this->invalidated);
        self::assertSame(42, (int) $this->event()['tstamp']);
        $this->imageUuid = 'ABCDEFGHIJKLMNOP';
        self::assertSame('success', $this->runSync()['status']);
        self::assertSame($this->imageUuid, $this->event()['ct_image_src'], 'A changed image under the same URL must be refreshed.');
        self::assertContains('contao.db.tl_calendar_events.'.$first['id'], $this->invalidated);
        $first = $this->event();
        $this->invalidated = [];
        $this->imageFailure = true;
        $this->remote->rows[0]['appointment']['base']['title'] = 'Changed';
        $this->remote->rows[0]['appointment']['base']['image']['imageUrl'] = 'https://example.org/images/7/replacement';
        self::assertSame('success', $this->runSync()['status']);
        self::assertSame($first['ct_image_src'], $this->event()['ct_image_src']);
        self::assertSame($first['ct_image_key'], $this->event()['ct_image_key']);
        self::assertSame('Changed', $this->event()['title']);
        self::assertSame('Editorial', $this->event()['teaser']);
        self::assertSame(0, (int) $this->event()['published']);
        $this->imageFailure = false;
        $this->imageUuid = 'abcdefghijklmnop';
        $this->invalidated = [];
        self::assertSame('success', $this->runSync()['status']);
        self::assertSame($this->imageUuid, $this->event()['ct_image_src']);
        self::assertContains('contao.db.tl_calendar_events.'.$first['id'], $this->invalidated);
        $this->remote->rows[0]['appointment']['base']['image'] = null;
        self::assertSame('success', $this->runSync()['status']);
        self::assertNull($this->event()['ct_image_src']);
        self::assertNull($this->event()['ct_image_key']);
    }

    public function testSharedImageIsFetchedOncePerRun(): void
    {
        $first = RemoteFixture::row('first');
        $first['appointment']['base']['image'] = ['id' => 7, 'imageUrl' => 'https://example.org/images/7/photo'];
        $second = $first;
        $second['appointment']['calculated']['iCalUid'] = 'second';
        $this->remote->rows = [$first, $second];
        self::assertSame('success', $this->runSync()['status']);
        self::assertCount(1, $this->imageCalls);
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_calendar_events WHERE ct_image_src IS NOT NULL'));
    }

    public function testImageContextUsesLocalStartDateAndStoredAlias(): void
    {
        $this->remote->rows[0]['appointment']['base']['image'] = ['id' => 7, 'imageUrl' => 'https://example.org/images/7/photo'];
        self::assertSame('success', $this->runSync()['status']);
        self::assertSame('2026-09-20', $this->imageCalls[0]['date']);
        self::assertSame($this->event()['alias'], $this->imageCalls[0]['alias']);
        $this->db->update('tl_calendar_events', ['alias' => 'editor-alias'], ['id' => $this->event()['id']]);
        self::assertSame('success', $this->runSync()['status']);
        self::assertSame('editor-alias', $this->imageCalls[1]['alias']);
    }

    public function testImageContextUsesLocalDateAfterTimezoneConversion(): void
    {
        $this->remote->rows[0] = RemoteFixture::row('local-midnight', '2026-09-20T22:30:00Z', '2026-09-20T23:30:00Z');
        $this->remote->rows[0]['appointment']['base']['image'] = ['id' => 7, 'imageUrl' => 'https://example.org/images/7/photo'];
        self::assertSame('success', $this->runSync()['status']);
        self::assertSame('2026-09-21', $this->imageCalls[0]['date']);
    }

    public function testUnsupportedOccurrenceReportsSafeIdentifiersWithoutExposingTitle(): void
    {
        $this->remote->rows = [RemoteFixture::row('bad-uid', '2026-09-20T08:00:00.123456Z', '2026-09-20T09:00:00Z')];
        $this->remote->rows[0]['appointment']['base']['title'] = 'PRIVATE_SENTINEL';
        $result = $this->runSync();
        self::assertSame('failed', $result['status']);
        self::assertStringContainsString('appointment 1', $result['error']);
        self::assertStringContainsString(hash('sha256', 'bad-uid'), $result['error']);
        self::assertStringNotContainsString('PRIVATE_SENTINEL', $result['error']);
    }

    public function testRemoteFetchDoesNotBlockEditorLock(): void
    {
        $params = (new DsnParser(['mysql' => 'pdo_mysql']))->parse(getenv('CHURCHTOOLS_TEST_DATABASE_URL'));
        $editor = DriverManager::getConnection($params);
        $lock = 'ct-calendar:'.substr(hash('sha256', $this->db->getDatabase().':1'), 0, 48);
        $fetchLock = 'ct-fetch:'.substr(hash('sha256', $this->db->getDatabase().':1'), 0, 48);
        $available = [];
        $competing = [];
        $this->remote->onRequest = static function () use ($editor, $lock, $fetchLock, &$available, &$competing): void {
            $competing[] = (int) $editor->fetchOne('SELECT GET_LOCK(?,0)', [$fetchLock]);
            $acquired = (int) $editor->fetchOne('SELECT GET_LOCK(?,0)', [$lock]);
            $available[] = $acquired;
            if ($acquired === 1) $editor->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
        };
        try {
            self::assertSame('success', $this->runSync()['status']);
            self::assertNotEmpty($available);
            self::assertSame([1], array_values(array_unique($available)));
            self::assertSame([0], array_values(array_unique($competing)), 'Concurrent sync fetches must remain serialized.');
        } finally {
            $editor->close();
        }
    }

    public function testUnchangedRunPreservesEditorialDataAliasAndDoesNotInvalidate(): void
    {
        self::assertSame('success', $this->runSync()['status']);
        $first = $this->event();
        self::assertSame(1, json_decode($this->db->fetchOne('SELECT ct_sync_counts FROM tl_calendar WHERE id=1') ?? '{}', true)['inserted'] ?? null);
        $this->db->update('tl_calendar_events', ['teaser' => 'Editorial', 'alias' => 'permanent-url', 'tstamp' => 42], ['id' => $first['id']]);
        $this->invalidated = [];
        self::assertSame('success', $this->runSync()['status']);
        self::assertSame('Editorial', $this->event()['teaser']);
        self::assertSame('permanent-url', $this->event()['alias']);
        self::assertSame(42, (int) $this->event()['tstamp']);
        self::assertSame([], $this->invalidated);
    }

    public function testChangedEventsInvalidateTheReaderRootSitemapOnly(): void
    {
        $this->db->update('tl_calendar', ['jumpTo' => 91], ['id' => 1]);
        self::assertSame('success', $this->runSync()['status']);
        self::assertContains('contao.sitemap.42', $this->invalidated);
        $this->invalidated = [];
        $this->remote->rows[0]['appointment']['base']['title'] = 'Changed title';
        self::assertSame('success', $this->runSync()['status']);
        self::assertContains('contao.sitemap.42', $this->invalidated);
        $this->invalidated = [];
        $this->runSync();
        self::assertSame([], $this->invalidated);
    }

    public function testVisibilityChangesInvalidateTheReaderRootSitemap(): void
    {
        $this->db->update('tl_calendar', ['jumpTo' => 91], ['id' => 1]);
        $this->runSync();
        $this->invalidated = [];
        $this->db->update('tl_calendar', ['ct_calendar_id' => null], ['id' => 1]);
        self::assertSame('success', $this->runSync()['status']);
        self::assertContains('contao.sitemap.42', $this->invalidated);
        $this->invalidated = [];
        $this->db->update('tl_calendar', ['ct_calendar_id' => 110], ['id' => 1]);
        self::assertSame('success', $this->runSync()['status']);
        self::assertContains('contao.sitemap.42', $this->invalidated);
    }

    public function testMissingReaderDoesNotInvalidateAnUnrelatedSitemap(): void
    {
        $this->db->update('tl_calendar', ['jumpTo' => 404], ['id' => 1]);
        self::assertSame('success', $this->runSync()['status']);
        self::assertSame([], array_values(array_filter($this->invalidated, static fn (string $tag): bool => str_starts_with($tag, 'contao.sitemap.'))));
    }

    public function testManualHideSurvivesChangesAndSyncHideReactivatesUnchanged(): void
    {
        $this->runSync();
        $this->db->update('tl_calendar_events', ['published' => 0, 'ct_unpublished_by_sync' => ''], ['id' => $this->event()['id']]);
        $this->remote->rows[0]['appointment']['base']['title'] = 'Changed';
        $this->runSync();
        self::assertSame(0, (int) $this->event()['published']);
        $this->db->update('tl_calendar_events', ['ct_unpublished_by_sync' => '1'], ['id' => $this->event()['id']]);
        $this->runSync();
        self::assertSame(1, (int) $this->event()['published']);
        self::assertSame('', $this->event()['ct_unpublished_by_sync']);
    }

    public function testDetachedOccurrenceKeepsSeriesAndIndividualContent(): void
    {
        $this->remote->rows[0]['appointment']['base']['repeatId'] = 1;
        $this->runSync();
        $before = $this->event();
        self::assertGreaterThan(0, (int) $before['ct_series_template_id']);
        $this->db->insert('tl_content', ['pid' => $before['id'], 'ptable' => 'tl_calendar_events']);
        $this->remote->rows[0]['appointment']['base']['id'] = 803;
        $this->remote->rows[0]['appointment']['base']['repeatId'] = 0;
        $this->remote->rows[0]['appointment']['calculated']['startDate'] = '2026-09-21T08:00:00Z';
        $this->remote->rows[0]['appointment']['calculated']['endDate'] = '2026-09-21T09:00:00Z';
        $this->runSync();
        self::assertSame($before['id'], $this->event()['id']);
        self::assertSame($before['ct_series_template_id'], $this->event()['ct_series_template_id']);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_content'));
    }

    public function testFailureLeavesImportedEventsAndLastSuccessUntouched(): void
    {
        $this->runSync();
        $before = $this->event();
        $last = $this->db->fetchOne('SELECT ct_last_sync FROM tl_calendar WHERE id=1');
        $this->remote->failure = '403';
        self::assertSame('failed', $this->runSync()['status']);
        self::assertSame($before, $this->event());
        self::assertSame($last, $this->db->fetchOne('SELECT ct_last_sync FROM tl_calendar WHERE id=1'));
    }

    public function testMissingEventIsHiddenButHistoryAndManualEventsSurvive(): void
    {
        $this->runSync();
        $this->db->insert('tl_calendar_events', ['pid' => 1, 'published' => '1', 'ct_sync_key' => str_repeat('a', 64), 'startTime' => 1, 'endTime' => 2]);
        $this->db->insert('tl_calendar_events', ['pid' => 1, 'published' => '1']);
        $this->remote->rows = [RemoteFixture::row('replacement')];
        $this->runSync();
        self::assertSame(0, (int) $this->event()['published']);
        self::assertSame('1', $this->event()['ct_unpublished_by_sync']);
        self::assertSame(3, (int) $this->db->fetchOne("SELECT COUNT(*) FROM tl_calendar_events WHERE published='1'"));
    }

    public function testAllDayAtUpperBoundaryIsRetainedAsOutsideWindow(): void
    {
        $this->runSync();
        $this->db->insert('tl_calendar_events', ['pid' => 1, 'published' => '1', 'ct_sync_key' => str_repeat('b', 64), 'startTime' => strtotime('2027-03-17 00:00:00'), 'endTime' => strtotime('2027-03-17 23:59:59'), 'addTime' => 0]);
        $this->runSync();
        self::assertSame(1, (int) $this->db->fetchOne('SELECT published FROM tl_calendar_events WHERE ct_sync_key=?', [str_repeat('b', 64)]));
    }

    public function testCommittedCacheErrorKeepsImportedDataAndSuccessTime(): void
    {
        $this->cacheFailure = true;
        self::assertSame('committed_cache_error', $this->runSync()['status']);
        self::assertSame(1, (int) $this->event()['published']);
        self::assertGreaterThan(0, (int) $this->db->fetchOne('SELECT ct_last_sync FROM tl_calendar WHERE id=1'));
    }

    public function testSeriesSplitRetainsOldContentAndCreatesDistinctEvents(): void
    {
        $this->remote->rows[0]['appointment']['base']['repeatId'] = 1;
        $this->runSync();
        $first = $this->event();
        $this->db->insert('tl_content', ['pid' => $first['id'], 'ptable' => 'tl_calendar_events']);
        $this->remote->rows[0]['appointment']['calculated']['iCalUid'] = 'new-series-uid';
        $this->remote->rows[0]['appointment']['base']['id'] = 815;
        $this->runSync();
        self::assertSame(0, (int) $this->event()['published']);
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_calendar_events'));
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_ct_series_templates'));
        self::assertSame($first['id'], $this->db->fetchOne('SELECT pid FROM tl_content'));
        self::assertSame((int) $first['id'], $this->warnings[0]['event']);
    }

    public function testStorageFailureRollsBackSeriesAndEventTogether(): void
    {
        $this->remote->rows[0]['appointment']['base']['repeatId'] = 1;
        $this->slugFailure = true;
        self::assertSame('failed', $this->runSync()['status']);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_calendar_events'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_ct_series_templates'));
        self::assertNull($this->db->fetchOne('SELECT ct_last_sync FROM tl_calendar WHERE id=1'));
    }

    public function testAssignmentChangeDuringRetrievalAbortsWithoutPartialImport(): void
    {
        $this->runSync();
        $first = $this->event();
        $this->remote->onRequest = function (): void { $this->db->update('tl_calendar', ['ct_calendar_id' => null], ['id' => 1]); };
        self::assertSame('failed', $this->runSync()['status']);
        self::assertSame($first, $this->event());
    }

    public function testDeselectionMakesNoApiRequestAndPreservesContent(): void
    {
        $this->runSync();
        $this->db->update('tl_calendar', ['ct_calendar_id' => null], ['id' => 1]);
        $requests = $this->remote->requests;
        $this->runSync();
        self::assertSame($requests, $this->remote->requests);
        self::assertSame(0, (int) $this->event()['published']);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_calendar_events'));
    }
}
