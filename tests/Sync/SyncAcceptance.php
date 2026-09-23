<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Tests\Sync;

use Contao\CalendarEventsModel;
use Contao\CalendarModel;
use Contao\CoreBundle\Event\InvalidateCacheTagsEvent;
use Doctrine\DBAL\DriverManager;
use Koertho\ChurchToolsBundle\Model\ChurchToolsArchiveModel as Archive;
use Koertho\ChurchToolsBundle\Model\ChurchToolsEntryModel as Entry;
use Koertho\ChurchToolsBundle\Sync\SnapshotFetcher;
use Koertho\ChurchToolsBundle\Sync\Synchronizer;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class SyncAcceptance
{
    public static function run(ContainerInterface $container): int
    {
        $checks = 0;
        $check = static function (bool $pass, string $label) use (&$checks): void {
            ++$checks;
            if (!$pass) {
                throw new \RuntimeException('Failed synchronization check: '.$label);
            }
        };
        $db = $container->get('database_connection');
        $a = new Archive();
        $a->name = 'Synthetic synchronization';
        $a->setCalendarIds([110]);
        $a->save();
        $id = (int) $a->id;
        $calendar = new CalendarModel();
        $calendar->title = 'Independent calendar';
        $calendar->save();
        $event = new CalendarEventsModel();
        $event->pid = $calendar->id;
        $event->title = 'Independent event';
        $event->save();
        $eventId = (int) $event->id;
        $eventBefore = $db->fetchAssociative('SELECT * FROM tl_calendar_events WHERE id=?', [$eventId]);
        $remote = new RemoteFixture();
        $remote->rows = [RemoteFixture::row('one'), RemoteFixture::row('two')];
        $cacheEvents = 0;
        $cacheFails = false;
        $dispatcher = $container->get('event_dispatcher');
        $listener = static function (InvalidateCacheTagsEvent $event) use (&$cacheEvents, &$cacheFails): void {
            if (in_array('contao.db.tl_church_tools_entry', $event->getTags(), true)) {
                ++$cacheEvents;
                if ($cacheFails) {
                    throw new \RuntimeException('Synthetic cache failure');
                }
            }
        };
        $dispatcher->addListener(InvalidateCacheTagsEvent::class, $listener);
        $sync = new Synchronizer(new SnapshotFetcher($remote->client()), $db, $container->get('contao.framework'), $container->get('contao.cache.tag_manager'));
        $run = static fn () => $sync->synchronize($id, new \DateTimeImmutable('2026-09-17T12:00:00Z'))[$id];
        $rows = static fn () => $db->fetchAllAssociative('SELECT * FROM tl_church_tools_entry WHERE pid=? ORDER BY id', [$id]);
        $status = static fn () => $db->fetchAssociative('SELECT * FROM tl_church_tools_archive WHERE id=?', [$id]);
        $result = $run();
        $check($result['status'] === 'success' && $result['inserted'] === 2, 'initial insertion');
        $check($cacheEvents === 2, 'cache tag events before and after commit');
        $check(!str_contains(json_encode($rows()), 'PRIVATE_SENTINEL'), 'persisted field allowlist');
        $first = $rows()[0];
        $link = Entry::findByPk($first['id']);
        $link->contaoEventId = $eventId;
        $link->save();
        $before = $rows();
        $check($run()['unchanged'] === 2 && $rows() === $before, 'byte-identical idempotency');
        $remote->rows[0]['appointment']['base']['id'] = 123;
        $remote->rows[0]['appointment']['calculated']['startDate'] = '2026-09-21T08:00:00Z';
        $remote->rows[0]['appointment']['calculated']['endDate'] = '2026-09-21T09:00:00Z';
        $remote->rows[0]['tags'] = [['id' => 1, 'name' => 'Tag', 'description' => null, 'color' => 'basic', 'count' => 42]];
        $check($run()['updated'] === 1, 'same UID moved update');
        $check($rows()[0]['id'] === $first['id'] && (int) $rows()[0]['contaoEventId'] === $eventId, 'move keeps row/link');
        $check(!str_contains($rows()[0]['tags'], 'count'), 'tag allowlist');
        array_shift($remote->rows);
        $result = $run();
        $check($result['sourceRemoved'] === 1 && $result['removedLinkedEntries'] === 1, 'source removal count');
        $db->update('tl_church_tools_archive', ['lastSuccessfulSync' => 1234567890], ['id' => $id]);
        $successState = $status();
        foreach (['missing', 'lost-after-fetch', 'duplicate-calendar', '401', '403', '500', 'malformed', 'partial', 'pagination', 'next', 'empty', 'split-mismatch'] as $failure) {
            $remote->failure = $failure;
            $remote->requests = 0;
            $before = $rows();
            $result = $run();
            $check($result['status'] === 'failed' && $rows() === $before, $failure.' retains rows');
            $check($status()['lastSuccessfulSync'] === $successState['lastSuccessfulSync'] && (int) $status()['removedLinkedEntries'] === 1 && $status()['lastSyncPastMonths'] === $successState['lastSyncPastMonths'], $failure.' preserves success/count');
        }
        $remote->failure = '';
        $remote->rows[] = RemoteFixture::row(str_repeat('x', 2049));
        $before = $rows();
        $check($run()['status'] === 'failed' && $rows() === $before, 'late invalid row before any writes');
        array_pop($remote->rows);
        $check($run()['removedLinkedEntries'] === 0 && $status()['lastError'] === null, 'successful clean run resets notice/error');
        $remote->rows[] = RemoteFixture::row('one');
        $check($run()['inserted'] === 1, 'source returns');
        $new = Entry::findOneBy(['pid=?', 'occurrenceUid=?'], [$id, 'one']);
        $check($new->contaoEventId === null, 'reappearance never restores link');
        $new->contaoEventId = $eventId;
        $new->save();
        $remote->rows[1]['appointment']['base']['isInternal'] = true;
        $result = $run();
        $check($result['internal'] === 1 && $result['sourceRemoved'] === 0 && $result['removedLinkedEntries'] === 0, 'internal UID classification');
        $check(count($rows()) === 1, 'internal never stored');
        $remote->rows[1]['appointment']['base']['isInternal'] = false;
        $check($run()['inserted'] === 1, 'public reappearance');
        $check($rows()[1]['contaoEventId'] === null, 'public reappearance has no link');
        // Classified cleanup uses source end, and preserves overlapping intervals.
        foreach ([['old', '2026-08-01', '2026-08-16'], ['future', '2027-03-17', '2027-03-18'], ['other', '2026-09-20', '2026-09-21']] as [$uid, $start, $end]) {
            $row = RemoteFixture::row($uid, $start, $end, true);
            if ($uid === 'other') {
                $row['appointment']['base']['calendar']['id'] = 2;
            }
            $entry = Entry::saveSource($id, $row);
            $entry->contaoEventId = $eventId;
            $entry->save();
        }
        $result = $run();
        $check($result['retention'] === 1 && $result['window'] === 1 && $result['deselected'] === 1 && $result['removedLinkedEntries'] === 0, 'cleanup categories');
        // Saved controlled fixtures establish recurrence, exceptions and accepted UID replacement.
        foreach (['repeat-baseline', 'repeat-after-move', 'repeat-after-delete', 'series-split-baseline', 'series-split-after'] as $fixture) {
            $remote->rows = RemoteFixture::saved($fixture);
            $result = $run();
            $check($result['status'] === 'success' && count($rows()) === count($remote->rows), $fixture.' reconciled');
            if ($fixture === 'repeat-baseline' || $fixture === 'series-split-baseline') {
                foreach (Entry::findByPid($id) as $entry) {
                    $entry->contaoEventId = $eventId;
                    $entry->save();
                }
            }
            if ($fixture === 'repeat-after-move') {
                $check(count(array_filter($rows(), static fn ($row) => (int) $row['contaoEventId'] === $eventId)) === 5, 'detached exception keeps every link');
            }
            if ($fixture === 'repeat-after-delete') {
                $check($result['removedLinkedEntries'] === 1, 'single recurrence deletion');
            }
            if ($fixture === 'series-split-after') {
                $check($result['removedLinkedEntries'] === 5 && $result['inserted'] === 5, 'split counts source removals');
                $check(array_filter($rows(), static fn ($row) => $row['contaoEventId'] !== null) === [], 'split no heuristic link transfer');
            }
        }
        $check($db->fetchAssociative('SELECT * FROM tl_calendar_events WHERE id=?', [$eventId]) === $eventBefore, 'all paths preserve entire core event');
        // Same remote identity in another archive stays independent.
        $b = new Archive();
        $b->name = 'Independent archive';
        $b->setCalendarIds([110]);
        $b->save();
        $otherEntry = Entry::saveSource((int) $b->id, $remote->rows[0]);
        $otherEntry->contaoEventId = 4294967294;
        $otherEntry->save();
        $otherBefore = $db->fetchAssociative('SELECT * FROM tl_church_tools_entry WHERE id=?', [$otherEntry->id]);
        $check($run()['status'] === 'success' && $db->fetchAssociative('SELECT * FROM tl_church_tools_entry WHERE id=?', [$otherEntry->id]) === $otherBefore, 'archive isolation');
        // Shrink both ends of the configured window; no source-removal notice.
        foreach ([['naturally-old', '2026-07-01', '2026-07-02'], ['past-shrink', '2026-09-01', '2026-09-02'], ['future-shrink', '2026-12-01', '2026-12-02']] as [$uid, $start, $end]) {
            $entry = Entry::saveSource($id, RemoteFixture::row($uid, $start, $end, true));
            $entry->contaoEventId = $eventId;
            $entry->save();
        }
        $short = new Synchronizer(new SnapshotFetcher($remote->client()), $db, $container->get('contao.framework'), $container->get('contao.cache.tag_manager'), 0, 1);
        $result = $short->synchronize($id, new \DateTimeImmutable('2026-09-17'))[$id];
        $check($result['retention'] === 1 && $result['window'] === 2 && $result['removedLinkedEntries'] === 0, 'changed window is not absence');
        // Editor changes selection while HTTP is in flight: do not write the stale plan.
        $before = $rows();
        $remote->onRequest = static function () use ($db, $id): void {
            $db->update('tl_church_tools_archive', ['calendarIds' => '[]'], ['id' => $id]);
        };
        $check($run()['status'] === 'failed' && $rows() === $before, 'configuration race rejected');
        $remote->onRequest = null;
        $db->update('tl_church_tools_archive', ['calendarIds' => '[110]'], ['id' => $id]);
        // Another connection holds the same advisory lock: no fetch, writes or status change.
        $otherDb = DriverManager::getConnection($db->getParams());
        $lock = substr('churchtools:'.hash('sha256', $db->getDatabase().':'.$id), 0, 64);
        $otherDb->fetchOne('SELECT GET_LOCK(?, 0)', [$lock]);
        $before = $status();
        $requests = $remote->requests;
        $check($run()['status'] === 'locked' && $remote->requests === $requests && $status() === $before, 'cross-connection contention');
        $otherDb->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
        $otherDb->close();
        // A new insertion followed by an invalid local row exercises real transaction rollback.
        $broken = Entry::saveSource($id, RemoteFixture::row('broken'));
        $db->update('tl_church_tools_entry', ['sourceStart' => 'invalid'], ['id' => $broken->id]);
        $remote->rows[] = RemoteFixture::row('rollback-new');
        $before = $rows();
        $check($run()['status'] === 'failed' && $rows() === $before, 'write transaction rolls back');
        $db->update('tl_church_tools_entry', ['sourceStart' => '2026-09-20T08:00:00Z'], ['id' => $broken->id]);
        $check($run()['status'] === 'success', 'retry after rollback has no stale model');
        $before = $status();
        $cacheFails = true;
        $check($run()['status'] === 'failed' && $status()['lastSuccessfulSync'] === $before['lastSuccessfulSync'] && $status()['removedLinkedEntries'] === $before['removedLinkedEntries'], 'cache failure retains success');
        $cacheFails = false;
        $check($run()['status'] === 'success', 'cache retry succeeds on unchanged data');
        $a = Archive::findByPk($id);
        $a->setCalendarIds([]);
        $a->save();
        $requests = $remote->requests;
        $result = $run();
        $check($result['status'] === 'success' && $result['deselected'] > 0 && $rows() === [] && $remote->requests === $requests, 'explicit deselect all without remote access');
        $check($result['removedLinkedEntries'] === 0, 'deselection excluded from notice');
        $a = Archive::findByPk($id);
        $a->setCalendarIds([999]);
        $a->save();
        $logger = new class extends \Psr\Log\AbstractLogger {
            public array $records = [];
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };
        (new \Koertho\ChurchToolsBundle\EventListener\Cron\SyncListener($sync, $logger))();
        $check(count($logger->records) >= 2 && $logger->records[0][2]['status'] === 'failed' && $logger->records[1][2]['status'] === 'success', 'cron continues independent archive after failure');
        $check(!str_contains(json_encode($logger->records), 'PRIVATE_SENTINEL'), 'cron diagnostics only');
        $a = Archive::findByPk($id);
        $a->setCalendarIds([]);
        $a->save();
        $dispatcher->removeListener(InvalidateCacheTagsEvent::class, $listener);

        return $checks;
    }
}
