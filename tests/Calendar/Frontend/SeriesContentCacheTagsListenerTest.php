<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Tests\Calendar\Frontend;

use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Koertho\ContaoChurchTools\EventListener\DataContainer\Content\ConfigOnInvalidateCacheTagsListener;
use PHPUnit\Framework\TestCase;

final class SeriesContentCacheTagsListenerTest extends TestCase
{
    public function testNestedContentAddsUltimateSeriesTag(): void
    {
        $rows = [10 => ['ptable' => 'tl_content', 'pid' => 9], 9 => ['ptable' => 'tl_ct_series_templates', 'pid' => 7]];
        $db = $this->createStub(Connection::class);
        $db->method('fetchAssociative')->willReturnCallback(static fn ($sql, $params) => $rows[$params[0]] ?? false);
        $dc = $this->createStub(DataContainer::class);
        $dc->id = 10;
        $tags = (new ConfigOnInvalidateCacheTagsListener($db))($dc, ['contao.db.tl_content.10']);
        self::assertContains('contao.db.tl_ct_series_templates.7', $tags);
        self::assertContains('contao.db.tl_content.10', $tags);
    }

    public function testDeletedNestedElementCanUseParentTagAndCyclesTerminate(): void
    {
        $rows = [9 => ['ptable' => 'tl_ct_series_templates', 'pid' => 7], 20 => ['ptable' => 'tl_content', 'pid' => 20]];
        $db = $this->createStub(Connection::class);
        $db->method('fetchAssociative')->willReturnCallback(static fn ($sql, $params) => $rows[$params[0]] ?? false);
        $dc = $this->createStub(DataContainer::class);
        $dc->id = 10;
        $listener = new ConfigOnInvalidateCacheTagsListener($db);
        self::assertContains('contao.db.tl_ct_series_templates.7', $listener($dc, ['contao.db.tl_content.10', 'contao.db.tl_content.9']));
        $dc->id = 20;
        self::assertSame([], $listener($dc, []));
    }
}
