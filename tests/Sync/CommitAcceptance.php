<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Tests\Sync;

use Contao\CalendarEventsModel;
use Contao\CalendarModel;
use Contao\CoreBundle\Event\InvalidateCacheTagsEvent;
use Contao\Model\Registry;
use Doctrine\DBAL\DriverManager;
use Koertho\ChurchToolsBundle\Model\ChurchToolsArchiveModel as Archive;
use Koertho\ChurchToolsBundle\Model\ChurchToolsEntryModel as Entry;
use Koertho\ChurchToolsBundle\Sync\SnapshotFetcher;
use Koertho\ChurchToolsBundle\Sync\Synchronizer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Real commit/rollback regression, deliberately WITHOUT an enclosing test transaction. */
final class CommitAcceptance
{
    public static function run(ContainerInterface $container): int
    {
        $db = $container->get('database_connection');
        if ($db->getTransactionNestingLevel() !== 0 || !str_starts_with((string) $db->fetchOne('SELECT DATABASE()'), 'churchtools_step3_')) {
            throw new \RuntimeException('Commit regression requires an isolated database and no outer transaction.');
        }
        $checks = 0;
        $check = static function (bool $pass, string $label) use (&$checks): void {
            ++$checks;
            if (!$pass) {
                throw new \RuntimeException('Commit regression: '.$label);
            }
        };
        $observer = DriverManager::getConnection($db->getParams());
        $dispatcher = $container->get('event_dispatcher');
        $models = [];
        $archiveIds = [];
        $calendarId = null;
        $eventId = null;
        $listener = null;
        $trigger = null;
        try {
            $calendar = new CalendarModel();
            $calendar->title = 'Commit regression '.bin2hex(random_bytes(8));
            $calendar->save();
            $models[] = $calendar;
            $calendarId = (int) $calendar->id;
            $event = new CalendarEventsModel();
            $event->pid = $calendarId;
            $event->title = 'Independent commit regression event';
            $event->save();
            $models[] = $event;
            $eventId = (int) $event->id;
            $eventBefore = $observer->fetchAssociative('SELECT * FROM tl_calendar_events WHERE id=?', [$eventId]);

            foreach (['dispatch', 'status', 'post-commit'] as $failure) {
                $archive = new Archive();
                $archive->name = 'Commit regression '.bin2hex(random_bytes(8));
                $archive->setCalendarIds([110]);
                $archive->lastSuccessfulSync = 1234567890;
                $archive->removedLinkedEntries = 7;
                $archive->lastSyncPastMonths = 2;
                $archive->tstamp = 1234567890;
                $archive->save();
                $models[] = $archive;
                $id = (int) $archive->id;
                $archiveIds[] = $id;
                $removed = Entry::saveSource($id, RemoteFixture::row('remove-linked'));
                $removed->contaoEventId = $eventId;
                $removed->save();
                $models[] = $removed;
                $models[] = Entry::saveSource($id, RemoteFixture::row('remaining'));
                $remote = new RemoteFixture();
                $remote->rows = [RemoteFixture::row('remaining')]; // Deliberately nonempty snapshot.
                $sync = new Synchronizer(new SnapshotFetcher($remote->client()), $db, $container->get('contao.framework'), $container->get('contao.cache.tag_manager'));
                $run = static fn () => $sync->synchronize($id, new \DateTimeImmutable('2026-09-17T12:00:00Z'))[$id];
                $rows = static fn () => $observer->fetchAllAssociative('SELECT * FROM tl_church_tools_entry WHERE pid=? ORDER BY id', [$id]);
                $state = static fn () => $observer->fetchAssociative('SELECT lastSuccessfulSync, removedLinkedEntries, lastSyncPastMonths, tstamp FROM tl_church_tools_archive WHERE id=?', [$id]);
                $beforeRows = $rows();
                $beforeState = $state();
                $enabled = true;
                $phases = [];
                $listener = static function (InvalidateCacheTagsEvent $event) use ($id, $failure, $db, $observer, &$enabled, &$phases, $beforeRows, $rows, $check): void {
                    if (!in_array('church_tools.archive.'.$id, $event->getTags(), true)) {
                        return;
                    }
                    $level = $db->getTransactionNestingLevel();
                    $phases[] = $level;
                    if ($level === 1) {
                        $check((int) $db->fetchOne('SELECT COUNT(*) FROM tl_church_tools_entry WHERE pid=?', [$id]) === 1, 'source removal precedes dispatch');
                        $check((int) $db->fetchOne('SELECT removedLinkedEntries FROM tl_church_tools_archive WHERE id=?', [$id]) === 1, 'success metadata is in source transaction');
                        $check($rows() === $beforeRows, 'separate reader still sees committed original rows before commit');
                        if ($enabled && $failure === 'dispatch') {
                            throw new \RuntimeException('Synthetic pre-commit dispatch failure');
                        }
                    } elseif ($level === 0) {
                        $check(count($rows()) === 1, 'post-commit invalidation sees durable source removal');
                        $check((int) $observer->fetchOne('SELECT removedLinkedEntries FROM tl_church_tools_archive WHERE id=?', [$id]) === 1, 'post-commit invalidation sees durable notice');
                        if ($enabled && $failure === 'post-commit') {
                            throw new \RuntimeException('Synthetic post-commit dispatch failure');
                        }
                    } else {
                        $check(false, 'no outer fixture transaction masks commit');
                    }
                };
                $dispatcher->addListener(InvalidateCacheTagsEvent::class, $listener);

                if ($failure === 'status') {
                    // A genuine SQL write failure, scoped to this newly created archive.
                    // DDL is outside transactions; this runner owns and removes this unique trigger.
                    $name = 'churchtools_test_status_'.bin2hex(random_bytes(8));
                    $db->executeStatement('SET @churchtools_test_status_failure = 0');
                    $db->executeStatement("CREATE TRIGGER $name BEFORE UPDATE ON tl_church_tools_archive FOR EACH ROW BEGIN IF OLD.id = $id AND NEW.lastSuccessfulSync <> OLD.lastSuccessfulSync THEN SET @churchtools_test_status_failure = (SELECT COUNT(*) FROM tl_church_tools_entry WHERE pid = $id); SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic status write failure'; END IF; END");
                    $trigger = $name;
                }
                $result = $run();
                $check($db->getTransactionNestingLevel() === 0, 'real transaction closed on return');
                if ($failure === 'post-commit') {
                    $check($result['status'] === 'committed_cache_error' && $result['removedLinkedEntries'] === 1, 'post-commit warning identifies committed result');
                    $check(count($rows()) === 1 && (int) $state()['removedLinkedEntries'] === 1 && (int) $state()['lastSuccessfulSync'] > 1234567890, 'post-commit error preserves matching committed data and metadata');
                    $check($phases === [1, 0], 'both invalidation phases executed');
                } else {
                    $check($result['status'] === 'failed', $failure.' returns failure');
                    $check($rows() === $beforeRows, $failure.' restores linked source row byte-for-byte');
                    $check($state() === $beforeState, $failure.' preserves all previous success fields');
                    $check(is_string($observer->fetchOne('SELECT lastError FROM tl_church_tools_archive WHERE id=?', [$id])), 'sanitized error recorded separately');
                    if ($trigger !== null) {
                        $check((int) $db->fetchOne('SELECT @churchtools_test_status_failure') === 1, 'status SQL failed after source removal inside the same transaction');
                        $db->executeStatement('DROP TRIGGER '.$trigger);
                        $trigger = null;
                    }
                    $enabled = false;
                    $phases = [];
                    $result = $run();
                    $check($result['status'] === 'success' && $result['sourceRemoved'] === 1 && $result['removedLinkedEntries'] === 1, $failure.' retry removes and counts exactly once');
                    $check(count($rows()) === 1 && (int) $state()['removedLinkedEntries'] === 1, 'retry commits data and notice together');
                    $check($phases === [1, 0], 'successful retry invalidates before and after real commit');
                }
                $dispatcher->removeListener(InvalidateCacheTagsEvent::class, $listener);
                $listener = null;
                $check($run()['removedLinkedEntries'] === 0 && (int) $state()['removedLinkedEntries'] === 0, 'following successful run does not count twice');
                $check($observer->fetchAssociative('SELECT * FROM tl_calendar_events WHERE id=?', [$eventId]) === $eventBefore, 'independent core event remains byte-identical');
            }
        } finally {
            if ($listener !== null) {
                $dispatcher->removeListener(InvalidateCacheTagsEvent::class, $listener);
            }
            if ($db->getTransactionNestingLevel() !== 0) {
                $db->close(); // Roll back any incomplete regression transaction before own-fixture cleanup.
            }
            if ($trigger !== null) {
                $db->executeStatement('DROP TRIGGER '.$trigger);
            }
            $db->executeStatement('SET @churchtools_test_status_failure = NULL');
            foreach ($archiveIds as $id) {
                $db->delete('tl_church_tools_entry', ['pid' => $id]);
                $db->delete('tl_church_tools_archive', ['id' => $id]);
            }
            if ($eventId !== null) {
                $db->delete('tl_calendar_events', ['id' => $eventId, 'pid' => $calendarId]);
            }
            if ($calendarId !== null) {
                $db->delete('tl_calendar', ['id' => $calendarId]);
            }
            foreach ($models as $model) {
                Registry::getInstance()->unregister($model);
            }
            $observer->close();
        }

        return $checks;
    }
}
