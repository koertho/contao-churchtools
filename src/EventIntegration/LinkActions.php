<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\EventIntegration;

use Contao\CalendarEventsModel;
use Contao\ContentModel;
use Contao\CoreBundle\Cache\CacheTagManager;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Model\Registry;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class LinkActions
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ContaoFramework $framework,
        private readonly BackendAccess $access,
        private readonly CacheTagManager $cacheTags,
    ) {
    }

    public function change(int $id, string $action, int $target = 0): ?int
    {
        $this->framework->initialize();
        $db = $this->connection;
        $pid = $db->fetchOne('SELECT pid FROM tl_church_tools_entry WHERE id=?', [$id]);
        if (!$pid) throw new NotFoundHttpException();
        $lock = substr('churchtools:'.hash('sha256', $db->getDatabase().':'.$pid), 0, 64);
        if ((int) $db->fetchOne('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) throw new ConflictHttpException('Archive is busy; retry.');
        $models = [];
        try {
            $result = $db->transactional(function () use ($db, $id, $pid, $action, $target, &$models): ?int {
                $db->fetchOne('SELECT id FROM tl_church_tools_archive WHERE id=? FOR UPDATE', [$pid]);
                $entry = $db->fetchAssociative('SELECT * FROM tl_church_tools_entry WHERE id=? AND pid=? FOR UPDATE', [$id, $pid]);
                if (!$entry) throw new NotFoundHttpException();
                $this->access->entry($entry);
                $existing = $entry['contaoEventId'] === null ? null : (int) $entry['contaoEventId'];
                if (!in_array($action, ['create', 'link', 'unlink'], true)) throw new \InvalidArgumentException('Unknown action.');
                // Includes dangling links: only explicit unlink can clear/replace them.
                if ($action !== 'unlink' && $existing !== null) {
                    if ($action === 'link' && $existing !== $target) throw new ConflictHttpException('Unlink first.');
                    return $existing;
                }
                $eventId = null;
                if ($action === 'link') {
                    $event = $db->fetchAssociative('SELECT * FROM tl_calendar_events WHERE id=? FOR UPDATE', [$target]);
                    if (!$event || !$this->access->target($event)) throw new AccessDeniedException();
                    $eventId = $target;
                } elseif ($action === 'create') {
                    $calendar = $db->fetchAssociative('SELECT * FROM tl_calendar WHERE id=? FOR UPDATE', [$target]);
                    if (!$calendar || !$this->access->calendar($calendar)) throw new AccessDeniedException();
                    $title = $entry['title'];
                    // Core 5 stores encoded titles; Core 6 event_list applies insert_tag to plaintext.
                    if (version_compare(\Contao\CoreBundle\ContaoCoreBundle::getVersion(), '6.0', '<')) {
                        $title = str_replace(['{', '}'], ['&#123;', '&#125;'], htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
                    }
                    if (version_compare(\Contao\CoreBundle\ContaoCoreBundle::getVersion(), '6.0', '>=') && str_contains($title, '{{')) throw new \DomainException('title_insert_tags_not_representable');
                    if (mb_strlen($title) > 255 || trim($entry['title']) === '') throw new \DomainException('title_not_representable');
                    $fields = EventFields::dates($entry, new \DateTimeZone(date_default_timezone_get()));
                    $text = EventFields::richText($entry['description']);
                    $event = new CalendarEventsModel();
                    $models[] = $event;
                    $event->setRow($fields + ['pid' => $target, 'tstamp' => time(), 'author' => (int) \Contao\BackendUser::getInstance()->id, 'title' => $title, 'source' => 'default', 'teaser' => $text, 'published' => '', 'alias' => '']);
                    $event->save();
                    $eventId = (int) $event->id;
                    // Numeric ID fallback is a native core URL contract and collision-free.
                    if ($text !== '') {
                        if (!$this->access->content($eventId)) throw new AccessDeniedException();
                        $content = new ContentModel();
                        $models[] = $content;
                        $content->setRow(['pid' => $eventId, 'ptable' => 'tl_calendar_events', 'sorting' => 128, 'tstamp' => time(), 'type' => 'text', 'text' => $text]);
                        $content->save();
                    }
                }
                $db->update('tl_church_tools_entry', ['contaoEventId' => $eventId], ['id' => $id]);
                $this->cacheTags->invalidateTags(['contao.db.tl_church_tools_entry', 'church_tools.archive.'.$pid, 'contao.db.tl_calendar_events', 'contao.db.tl_content']);
                return $eventId;
            });
            // A failure here is reported; retry remains idempotent after commit.
            $this->cacheTags->invalidateTags(['contao.db.tl_church_tools_entry', 'church_tools.archive.'.$pid, 'contao.db.tl_calendar_events', 'contao.db.tl_content']);
            return $result;
        } finally {
            foreach ($models as $model) Registry::getInstance()->unregister($model);
            $db->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
}
