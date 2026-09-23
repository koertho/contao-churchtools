<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Sync;

use Contao\CoreBundle\Cache\CacheTagManager;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Model\Registry;
use Doctrine\DBAL\Connection;
use Koertho\ChurchToolsBundle\Api\ApiException;
use Koertho\ChurchToolsBundle\Model\ChurchToolsArchiveModel as Archive;
use Koertho\ChurchToolsBundle\Model\ChurchToolsEntryModel as Entry;

final class Synchronizer
{
    public function __construct(
        private readonly SnapshotFetcher $fetcher,
        private readonly Connection $connection,
        private readonly ContaoFramework $framework,
        private readonly CacheTagManager $cacheTags,
        private readonly int $pastMonths = 1,
        private readonly int $futureMonths = 6,
    ) {
    }

    /** Only IDs, fixed diagnostics and aggregate counters leave the service. */
    public function synchronize(?int $archiveId = null, ?\DateTimeImmutable $now = null): array
    {
        $this->framework->initialize();
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $window = SyncWindow::at($now, $this->pastMonths, $this->futureMonths);
        $ids = $archiveId === null ? $this->connection->fetchFirstColumn('SELECT id FROM tl_church_tools_archive ORDER BY id') : [$archiveId];
        $results = [];
        foreach ($ids as $id) {
            $results[(int) $id] = $this->archive((int) $id, $window, $now);
        }

        return $results;
    }

    private function archive(int $id, SyncWindow $window, \DateTimeImmutable $now): array
    {
        $db = $this->connection;
        // Connection-scoped MariaDB/MySQL advisory lock: shared by CLI/cron across hosts,
        // no TTL expiry mid-fetch, no lock/status table. Never wait behind another run.
        $lock = 'churchtools:'.hash('sha256', $db->getDatabase().':'.$id);
        $lock = substr($lock, 0, 64);
        if ((int) $db->fetchOne('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
            return ['status' => 'locked'];
        }
        $models = [];
        $transaction = false;
        $committed = false;
        try {
            $archive = Archive::findByPk($id);
            if ($archive === null) {
                return ['status' => 'missing'];
            }
            $models[] = $archive;
            $archive->refresh();
            $selection = $archive->calendarIds;
            $ids = $selection === null ? [] : json_decode($selection, true, flags: JSON_THROW_ON_ERROR);
            $archive->setCalendarIds($ids);
            $snapshot = $this->fetcher->fetch($ids, $window);
            $counts = array_fill_keys(['inserted', 'updated', 'unchanged', 'internal', 'retention', 'window', 'deselected', 'sourceRemoved', 'removedLinkedEntries'], 0);
            $db->beginTransaction();
            $transaction = true;
            $current = $db->fetchAssociative('SELECT * FROM tl_church_tools_archive WHERE id=? FOR UPDATE', [$id]);
            if (!$current || $current['calendarIds'] !== $selection) {
                throw new ApiException('Archive selection changed during retrieval; archive unchanged.');
            }
            $archive->refresh();
            $previousWindow = SyncWindow::at($now, $archive->lastSyncPastMonths === null ? $this->pastMonths : (int) $archive->lastSyncPastMonths, $this->futureMonths);
            $db->fetchFirstColumn('SELECT id FROM tl_church_tools_entry WHERE pid=? FOR UPDATE', [$id]);
            $existing = [];
            foreach (Entry::findByPid($id) ?? [] as $entry) {
                $entry->refresh();
                $models[] = $entry;
                $existing['uid:'.$entry->occurrenceUid] = $entry;
            }
            foreach ($snapshot as $key => $item) {
                $fields = $item['fields'];
                if ($item['internal'] || $window->outside($fields) !== null) {
                    continue;
                }
                $entry = $existing[$key] ?? null;
                if ($entry !== null) {
                    $same = true;
                    foreach ($fields as $name => $value) {
                        if ($value === null ? $entry->$name !== null : (string) $entry->$name !== (string) $value) {
                            $same = false;
                            break;
                        }
                    }
                    if ($same) {
                        ++$counts['unchanged'];
                        unset($existing[$key]);
                        continue;
                    }
                }
                $row = ['appointment' => ['base' => [
                    'id' => $fields['sourceAppointmentId'], 'calendar' => ['id' => $fields['sourceCalendarId'], 'name' => $fields['calendarName']],
                    'title' => $fields['title'], 'description' => $fields['description'], 'allDay' => $fields['allDay'], 'isInternal' => false,
                ], 'calculated' => ['iCalUid' => $fields['occurrenceUid'], 'startDate' => $fields['sourceStart'], 'endDate' => $fields['sourceEnd']]],
                    'tags' => json_decode($fields['tags'], true, flags: JSON_THROW_ON_ERROR)];
                $models[] = Entry::saveSource($id, $row);
                ++$counts[$entry === null ? 'inserted' : 'updated'];
                unset($existing[$key]);
            }
            foreach ($existing as $key => $entry) {
                $item = $snapshot[$key] ?? null;
                $reason = !in_array((int) $entry->sourceCalendarId, $ids, true) ? 'deselected'
                    : (($item['internal'] ?? false) ? 'internal' : $window->outside($item['fields'] ?? $entry->row()));
                if ($reason === 'retention' && $previousWindow->outside($item['fields'] ?? $entry->row()) !== 'retention') {
                    $reason = 'window';
                }
                $reason ??= 'sourceRemoved';
                ++$counts[$reason];
                if ($reason === 'sourceRemoved' && $entry->contaoEventId !== null) {
                    ++$counts['removedLinkedEntries'];
                }
                $entry->delete();
            }
            $db->update('tl_church_tools_archive', ['lastSuccessfulSync' => time(), 'lastError' => null,
                'removedLinkedEntries' => $counts['removedLinkedEntries'], 'lastSyncPastMonths' => $this->pastMonths, 'tstamp' => time()], ['id' => $id]);
            $tags = ['contao.db.tl_church_tools_entry', 'contao.db.tl_church_tools_archive', 'church_tools.archive.'.$id];
            // Contao dispatches synchronously, then queues FOS invalidations. Both this
            // call and the success metadata belong inside the source-write transaction.
            $this->cacheTags->invalidateTags($tags);
            $db->commit();
            $transaction = false;
            $committed = true;
            // Immediate listeners/custom transports may have evicted before commit,
            // allowing a reader to recache old data. Reissue after commit as well.
            // Standard FOS transport still flushes at console/kernel termination.
            try {
                $this->cacheTags->invalidateTags($tags);
            } catch (\Throwable) {
                $message = 'Synchronization committed; post-commit cache invalidation failed. Retry invalidation; source removal count is retained.';
                $db->update('tl_church_tools_archive', ['lastError' => $message], ['id' => $id]);

                return ['status' => 'committed_cache_error', 'error' => $message] + $counts;
            }

            return ['status' => 'success'] + $counts;
        } catch (\Throwable $error) {
            if ($committed) {
                // Even persisting a cache warning may fail. Never downgrade or replace
                // the already committed success metadata/removal count.
                return ['status' => 'committed_cache_error', 'error' => 'Synchronization committed; cache warning could not be stored.'] + $counts;
            }
            if ($transaction) {
                try {
                    $db->rollBack();
                } catch (\Throwable) {
                    // A server-side transaction abort can invalidate nested savepoints.
                    // Do not continue with uncertain connection/transaction state.
                    $db->close();
                    throw new ApiException('Synchronization transaction was aborted; retry with a fresh connection.');
                }
            }
            // Never persist/log transport, DBAL or mapper exception details or chains.
            $message = $error instanceof ApiException ? $error->getMessage() : 'Synchronization validation, storage or cache operation failed; last success retained.';
            $db->update('tl_church_tools_archive', ['lastError' => $message], ['id' => $id]);

            return ['status' => 'failed', 'error' => $message];
        } finally {
            // Rollbacks and direct status writes must not leave stale Active Records cached.
            foreach ($models as $model) {
                Registry::getInstance()->unregister($model);
            }
            $db->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
}
