<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\EventIntegration;

use Contao\ArticleModel;
use Contao\CalendarEventsModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\CoreBundle\Routing\Content\ContentUrlResolverInterface;
use Contao\CoreBundle\Routing\Content\StringUrl;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\Date;
use Contao\PageModel;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/** No result cache: visibility is evaluated for the current visitor on every call. */
final class DisplayResolver
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ContaoFramework $framework,
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly ContentUrlGenerator $urls,
        #[AutowireIterator('contao.content_url_resolver')] private readonly iterable $urlResolvers,
    ) {
    }

    /** Inclusive overlap; point occurrences remain points. No recurrence expansion or day duplication. */
    public function resolve(array $archiveIds, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        $this->framework->initialize();
        $ids = array_values(array_unique(array_filter(array_map(intval(...), $archiveIds), static fn (int $id): bool => $id > 0)));
        if (!$ids) return [];
        $rows = $this->connection->fetchAllAssociative('SELECT e.* FROM tl_church_tools_entry e INNER JOIN tl_church_tools_archive a ON a.id=e.pid WHERE e.pid IN (?) ORDER BY e.pid,e.id', [$ids], [ArrayParameterType::INTEGER]);
        $targetIds = array_values(array_unique(array_filter(array_column($rows, 'contaoEventId'))));
        $targets = $calendars = [];
        if ($targetIds) {
            foreach ($this->connection->fetchAllAssociative('SELECT * FROM tl_calendar_events WHERE id IN (?)', [$targetIds], [ArrayParameterType::INTEGER]) as $row) $targets[(int) $row['id']] = $row;
            $calendarIds = array_values(array_unique(array_column($targets, 'pid')));
            if ($calendarIds) foreach ($this->connection->fetchAllAssociative('SELECT * FROM tl_calendar WHERE id IN (?)', [$calendarIds], [ArrayParameterType::INTEGER]) as $row) $calendars[(int) $row['id']] = $row;
        }
        $winners = [];
        $timezone = new \DateTimeZone(date_default_timezone_get());
        $now = Date::floorToMinute();
        foreach ($rows as $row) {
            $start = (new \DateTimeImmutable($row['sourceStart'], $timezone))->setTimezone($timezone);
            $end = (new \DateTimeImmutable($row['sourceEnd'], $timezone))->setTimezone($timezone);
            if ($row['allDay']) $end = $end->modify('+1 day')->modify('-1 microsecond');
            $effective = ['id' => (int) $row['id'], 'archiveId' => (int) $row['pid'], 'uid' => $row['occurrenceUid'], 'title' => $row['title'], 'description' => $row['description'], 'descriptionIsHtml' => false, 'start' => $start, 'end' => $end, 'allDay' => (bool) $row['allDay'], 'url' => null, 'targetId' => null];
            $target = $targets[$row['contaoEventId']] ?? null;
            $calendar = $target ? ($calendars[$target['pid']] ?? null) : null;
            if ($target && $calendar && $this->published($target, $now) && $this->accessible($calendar)
                && is_numeric($target['startTime']) && is_numeric($target['endTime']) && (int) $target['endTime'] >= (int) $target['startTime']) {
                $model = new CalendarEventsModel();
                $model->setRow($target);
                try {
                    if ($this->visibleDestination($model, $now)) {
                        $url = $this->urls->generate($model);
                        if ($url !== '' && !preg_match('~^(?:javascript|data):~i', $url)) {
                            $effective = array_replace($effective, ['title' => (version_compare(\Contao\CoreBundle\ContaoCoreBundle::getVersion(), '6.0', '<') ? html_entity_decode($target['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8') : $target['title']), 'description' => $target['teaser'], 'descriptionIsHtml' => true,
                                'start' => (new \DateTimeImmutable('@'.$target['startTime']))->setTimezone($timezone), 'end' => (new \DateTimeImmutable('@'.$target['endTime']))->setTimezone($timezone),
                                'allDay' => !$target['addTime'], 'url' => $url, 'targetId' => (int) $target['id']]);
                            if ($target['addTime'] && (int) $target['startTime'] === (int) $target['endTime']) {
                                // Core's intentional open-ended event semantics apply only to editorial targets.
                                $effective['end'] = $effective['end']->setTime(23, 59, 59);
                            }
                        }
                    }
                } catch (\Contao\CoreBundle\Exception\NoRootPageFoundException|\Contao\CoreBundle\Exception\ForwardPageNotFoundException|\Symfony\Component\Routing\Exception\ExceptionInterface) {
                    // An unusable destination cannot expose target information.
                }
            }
            $key = 'uid:'.$row['occurrenceUid'];
            if (!isset($winners[$key]) || ($effective['targetId'] !== null && $winners[$key]['targetId'] === null)) $winners[$key] = $effective;
        }
        // Resolve and choose a UID winner BEFORE filtering. Never fall back to another copy.
        $result = array_values(array_filter($winners, static fn (array $row): bool => $row['start'] <= $until && $row['end'] >= $from));
        usort($result, static fn (array $a, array $b): int => $a['start'] <=> $b['start'] ?: $a['archiveId'] <=> $b['archiveId'] ?: $a['id'] <=> $b['id']);
        return $result;
    }

    private function published(array $row, int $now): bool
    {
        return (int) $row['published'] === 1 && ($row['start'] === '' || (int) $row['start'] <= $now) && ($row['stop'] === '' || (int) $row['stop'] > $now);
    }

    private function accessible(array $row): bool
    {
        return !($row['protected'] ?? false) || $this->authorization->isGranted(ContaoCorePermissions::MEMBER_IN_GROUPS, $row['groups']);
    }

    /** Follow the same ordered resolvers as Core, checking every visited page/article. */
    private function visibleDestination(object $content, int $now, array $seen = []): bool
    {
        $key = $content::class.':'.($content instanceof StringUrl ? spl_object_id($content) : ($content->id ?? spl_object_id($content)));
        if (isset($seen[$key]) || count($seen) > 16) return false;
        $seen[$key] = true;
        if ($content instanceof PageModel) {
            $content->loadDetails();
            if (!$content->isPublic || !$content->rootIsPublic || !$this->accessible($content->row())) return false;
        } elseif ($content instanceof ArticleModel && (!$this->published($content->row(), $now) || !$this->accessible($content->row()))) {
            return false;
        }
        foreach ($this->urlResolvers as $resolver) {
            /** @var ContentUrlResolverInterface $resolver */
            $result = $resolver->resolve($content);
            if ($result === null) continue;
            return $result->hasTargetUrl() || $this->visibleDestination($result->content, $now, $seen);
        }
        return $content instanceof PageModel;
    }
}
