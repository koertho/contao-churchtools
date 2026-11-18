<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Calendar\Sync;

use Contao\CoreBundle\Cache\CacheTagManager;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Slug\Slug;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Koertho\ContaoChurchTools\Api\ApiException;
use Psr\Log\LoggerInterface;

final class CalendarEventSynchronizer
{
    public function __construct(
        private readonly SnapshotFetcher $fetcher,
        private readonly CalendarEventMapper $mapper,
        private readonly SeriesTemplateManager $series,
        private readonly Connection $connection,
        private readonly ContaoFramework $framework,
        private readonly CacheTagManager $cacheTags,
        private readonly LoggerInterface $logger,
        private readonly Slug $slug,
        private readonly int $pastMonths = 1,
        private readonly int $futureMonths = 6,
    ) {
    }

    /** Only local IDs, fixed diagnostics and counters leave the synchronizer. */
    public function synchronize(?int $archiveId = null, ?\DateTimeImmutable $now = null): array
    {
        $this->framework->initialize();
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $window = SyncWindow::at($now, $this->pastMonths, $this->futureMonths);
        $timezone = new \DateTimeZone(date_default_timezone_get());
        $ids = $archiveId === null ? $this->connection->fetchFirstColumn('SELECT c.id FROM tl_calendar c WHERE c.ct_calendar_id IS NOT NULL OR EXISTS (SELECT 1 FROM tl_calendar_events e WHERE e.pid=c.id AND e.ct_sync_key IS NOT NULL) ORDER BY c.id') : [$archiveId];
        $results = [];
        foreach ($ids as $id) {
            $results[(int) $id] = $this->archive((int) $id, $window, $timezone, $now->getTimestamp());
        }

        return $results;
    }

    private function archive(int $id, SyncWindow $window, \DateTimeZone $timezone, int $now): array
    {
        $db = $this->connection;
        $lock = 'ct-calendar:'.substr(hash('sha256', $db->getDatabase().':'.$id), 0, 48);
        if ((int) $db->fetchOne('SELECT GET_LOCK(?,0)', [$lock]) !== 1) {
            return ['status' => 'locked'];
        }
        $transaction = false;
        $committed = false;
        $counts = array_fill_keys(['inserted', 'updated', 'unchanged', 'unpublished', 'reactivated', 'seriesUpdated'], 0);
        try {
            $archive = $db->fetchAssociative('SELECT * FROM tl_calendar WHERE id=?', [$id]);
            if (!$archive) {
                return ['status' => 'missing'];
            }
            $calendarId = $archive['ct_calendar_id'] === null ? null : (int) $archive['ct_calendar_id'];
            $snapshot = $this->fetcher->fetch($calendarId === null ? [] : [$calendarId], $window);
            $mapped = [];
            foreach ($snapshot as $item) {
                if ($item['internal'] || $window->outside($item['fields']) !== null) {
                    continue;
                }
                $fields = $this->mapper->map($item['fields'], $timezone);
                $mapped[$fields['ct_sync_key']] = $fields;
            }
            $db->beginTransaction();
            $transaction = true;
            $current = $db->fetchAssociative('SELECT * FROM tl_calendar WHERE id=? FOR UPDATE', [$id]);
            if (!$current || ($current['ct_calendar_id'] === null ? null : (int) $current['ct_calendar_id']) !== $calendarId) {
                throw new ApiException('Calendar assignment changed during retrieval; archive unchanged.');
            }
            $existing = [];
            foreach ($db->fetchAllAssociative('SELECT * FROM tl_calendar_events WHERE pid=? AND ct_sync_key IS NOT NULL FOR UPDATE', [$id]) as $event) {
                $existing[$event['ct_sync_key']] = $event;
            }
            $tags = [];
            $warnings = [];
            foreach ($mapped as $key => $fields) {
                $event = $existing[$key] ?? null;
                $previousSeries = empty($event['ct_series_template_id']) ? null : (int) $event['ct_series_template_id'];
                [$seriesId, $changedSeries] = $this->series->synchronize($fields, $previousSeries, $now);
                foreach ($changedSeries as $changedId) {
                    $tags[] = 'contao.db.tl_ct_series_templates.'.$changedId;
                    ++$counts['seriesUpdated'];
                }
                $rawTitle = $fields['_sourceTitle'];
                $localDate = $fields['_localDate'];
                unset($fields['_sourceTitle'], $fields['_localDate'], $fields['_repeatId']);
                $fields['ct_series_template_id'] = $seriesId;
                $fields['ct_last_sync'] = $now;
                if ($event === null) {
                    $alias = $this->slug->generate($rawTitle.'-'.$localDate, (int) $archive['jumpTo'], static fn (string $alias): bool => (bool) $db->fetchOne('SELECT id FROM tl_calendar_events WHERE alias=? LIMIT 1', [$alias]));
                    if ($alias === '' || strlen($alias) > 255) {
                        throw new \DomainException('Generated alias exceeds native event limits.');
                    }
                    $db->insert('tl_calendar_events', $fields + ['pid' => $id, 'tstamp' => $now, 'published' => '1', 'ct_unpublished_by_sync' => '', 'alias' => $alias]);
                    $eventId = (int) $db->lastInsertId();
                    ++$counts['inserted'];
                    $tags[] = 'contao.db.tl_calendar_events.'.$eventId;
                } else {
                    $eventId = (int) $event['id'];
                    $reactivate = !in_array($event['published'], [true, 1, '1'], true) && $event['ct_unpublished_by_sync'] === '1';
                    $changed = $event['ct_hash'] !== $fields['ct_hash'] || $previousSeries !== $seriesId;
                    if ($changed || $reactivate) {
                        $update = $changed ? $fields : ['ct_last_sync' => $now];
                        $update['tstamp'] = $now;
                        if ($reactivate) {
                            $update['published'] = '1';
                            $update['ct_unpublished_by_sync'] = '';
                            ++$counts['reactivated'];
                        }
                        $db->update('tl_calendar_events', $update, ['id' => $eventId]);
                        ++$counts['updated'];
                        $tags[] = 'contao.db.tl_calendar_events.'.$eventId;
                    } else {
                        $db->update('tl_calendar_events', ['ct_last_sync' => $now], ['id' => $eventId]);
                        ++$counts['unchanged'];
                    }
                }
                unset($existing[$key]);
            }
            foreach ($existing as $event) {
                // Date-only all-day bounds must be compared as local calendar dates,
                // not against UTC instants (especially at the upper boundary).
                $start = (int) $event['startTime'];
                $end = (int) $event['endTime'];
                $timed = in_array($event['addTime'], [true, 1, '1'], true);
                $inside = $timed
                    ? $start < $window->to->getTimestamp() && ($end > $window->from->getTimestamp() || ($start === $end && $end === $window->from->getTimestamp()))
                    : (new \DateTimeImmutable('@'.$start))->setTimezone($timezone)->format('Y-m-d') < $window->to->format('Y-m-d')
                        && (new \DateTimeImmutable('@'.$end))->setTimezone($timezone)->format('Y-m-d') >= $window->from->format('Y-m-d');
                if (!$inside || !in_array($event['published'], [true, 1, '1'], true)) {
                    continue;
                }
                $eventId = (int) $event['id'];
                $db->update('tl_calendar_events', ['published' => '0', 'ct_unpublished_by_sync' => '1', 'tstamp' => $now], ['id' => $eventId]);
                ++$counts['unpublished'];
                $tags[] = 'contao.db.tl_calendar_events.'.$eventId;
                if ($db->fetchOne("SELECT id FROM tl_content WHERE pid=? AND ptable='tl_calendar_events' LIMIT 1", [$eventId])) {
                    $warnings[] = $eventId;
                }
            }
            $db->update('tl_calendar', ['ct_last_sync' => $now, 'ct_sync_error' => null, 'ct_sync_counts' => json_encode($counts, JSON_THROW_ON_ERROR)], ['id' => $id]);
            if ($tags !== []) {
                $tags[] = 'contao.db.tl_calendar.'.$id;
                $tags[] = 'contao.db.tl_calendar_events';
            }
            if (($counts['inserted'] + $counts['updated'] + $counts['unpublished']) > 0 && (int) $current['jumpTo'] > 0) {
                // Match the native calendar DCA's sitemap invalidation: source changes
                // bypass its model callbacks, including the reader page's root tag.
                $reader = $this->framework->getAdapter(PageModel::class)->findWithDetails((int) $current['jumpTo']);
                if ($reader !== null && (int) $reader->rootId > 0) {
                    $tags[] = 'contao.sitemap.'.(int) $reader->rootId;
                }
            }
            $db->commit();
            $transaction = false;
            $committed = true;
            if ($tags !== []) {
                $this->cacheTags->invalidateTags(array_values(array_unique($tags)));
            }
            foreach ($warnings as $eventId) {
                $this->logger->warning('ChurchTools occurrence disappeared; individual content was retained on the unpublished event. Review a possible move or series split.', ['archive' => $id, 'event' => $eventId]);
            }

            return ['status' => 'success'] + $counts;
        } catch (\Throwable $error) {
            if ($transaction) {
                $db->rollBack();
            }
            $message = $committed ? 'Synchronization committed; post-commit cache invalidation or reporting failed. Retry cache invalidation.'
                : ($error instanceof ApiException ? $error->getMessage() : 'Synchronization validation or storage failed; archive unchanged.');
            $db->update('tl_calendar', ['ct_sync_error' => $message], ['id' => $id]);

            return ['status' => $committed ? 'committed_cache_error' : 'failed', 'error' => $message] + ($committed ? $counts : []);
        } finally {
            $db->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
}
