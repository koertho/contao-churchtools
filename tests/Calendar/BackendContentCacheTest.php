<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Tests\Calendar;

use Contao\CoreBundle\Cache\CacheTagManager;
use Contao\CoreBundle\Event\InvalidateCacheTagsEvent;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Koertho\ContaoChurchTools\Calendar\Backend\ContentSeriesCache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class BackendContentCacheTest extends TestCase
{
    public function testMovingNestedContentInvalidatesOldAndNewSeries(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE tl_content (id INTEGER PRIMARY KEY,pid INTEGER,ptable TEXT)');
        $db->insert('tl_content', ['id' => 1, 'pid' => 7, 'ptable' => 'tl_ct_series_templates']);
        $db->insert('tl_content', ['id' => 2, 'pid' => 1, 'ptable' => 'tl_content']);
        $dispatcher = new EventDispatcher();
        $tags = [];
        $dispatcher->addListener(InvalidateCacheTagsEvent::class, static function (InvalidateCacheTagsEvent $event) use (&$tags): void { $tags = $event->getTags(); });
        $cache = new ContentSeriesCache($db, new CacheTagManager($this->createStub(EntityManagerInterface::class), $dispatcher));
        $cache->capture(2);
        $db->update('tl_content', ['ptable' => 'tl_ct_series_templates', 'pid' => 8], ['id' => 2]);
        $cache->invalidate(2);
        sort($tags);
        self::assertSame(['contao.db.tl_ct_series_templates.7', 'contao.db.tl_ct_series_templates.8'], $tags);
    }

    public function testCopiedContentInvalidatesItsDestinationSeries(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE tl_content (id INTEGER PRIMARY KEY,pid INTEGER,ptable TEXT)');
        $db->insert('tl_content', ['id' => 3, 'pid' => 8, 'ptable' => 'tl_ct_series_templates']);
        $dispatcher = new EventDispatcher();
        $tags = [];
        $dispatcher->addListener(InvalidateCacheTagsEvent::class, static function (InvalidateCacheTagsEvent $event) use (&$tags): void { $tags = $event->getTags(); });
        $cache = new ContentSeriesCache($db, new CacheTagManager($this->createStub(EntityManagerInterface::class), $dispatcher));
        $cache->invalidate(3);
        self::assertSame(['contao.db.tl_ct_series_templates.8'], $tags);
    }
}
