<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Tests\Calendar\Frontend;

use Contao\ContentModel;
use Contao\Controller;
use Contao\CoreBundle\Cache\CacheTagManager;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use FOS\HttpCache\ResponseTagger;
use Koertho\ContaoChurchTools\Calendar\Frontend\EventContentResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class EventContentResolverTest extends TestCase
{
    private array $series = [];
    private ResponseTagger $tagger;
    private array $files = [];

    protected function setUp(): void
    {
        ContentFixtures::$content = [];
        ContentFixtures::$renders = 0;
        ContentFixtures::$queries = 0;
        $this->tagger = new ResponseTagger();
    }

    private function resolver(?RequestStack $requests = null): EventContentResolver
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturnCallback(fn () => $this->series ?: false);
        $connection->method('fetchOne')->willReturnCallback(fn ($sql, $params) => $this->files[$params[0]] ?? false);
        $framework = $this->createStub(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnCallback(static fn (string $class) => new Adapter(match ($class) {
            ContentModel::class, Controller::class => ContentFixtures::class,
            default => $class,
        }));
        $tags = new CacheTagManager($this->createStub(EntityManagerInterface::class), new EventDispatcher(), $this->tagger);

        return new EventContentResolver($connection, $framework, $tags, sys_get_temp_dir(), $requests);
    }

    public function testIndividualTeaserWinsAndManualEventsStayUnchanged(): void
    {
        $this->series = ['id' => 7, 'teaser' => '<p>Series</p>'];
        $resolver = $this->resolver();
        $manual = ['id' => 1, 'teaser' => '<p>Manual</p>'];
        self::assertSame($manual, $resolver->resolve($manual));
        $event = $resolver->resolve(['id' => 2, 'ct_sync_key' => 'key', 'ct_series_template_id' => 7, 'teaser' => '<p>Own</p>', 'ct_description' => 'Source']);
        self::assertSame('<p>Own</p>', $event['teaser']);
        self::assertTrue($event['hasTeaser']);
    }

    public function testSeriesAndSafeSourceDescriptionFallbacks(): void
    {
        $this->series = ['id' => 7, 'teaser' => '<p>Series</p>'];
        $event = $this->resolver()->resolve(['id' => 2, 'ct_sync_key' => 'key', 'ct_series_template_id' => 7, 'teaser' => '', 'ct_description' => 'Source']);
        self::assertSame('<p>Series</p>', $event['teaser']);
        self::assertStringContainsString('contao.db.tl_ct_series_templates.7', $this->tagger->getTagsHeaderValue());
        $this->series = [];
        $event = $this->resolver()->resolve(['id' => 2, 'ct_sync_key' => 'key', 'teaser' => '', 'ct_description' => "<b>literal</b>\n{{env::host}}"]);
        self::assertSame('<p>&lt;b&gt;literal&lt;/b&gt;<br>&#123;&#123;env::host&#125;&#125;</p>', $event['teaser']);
    }

    public function testContentIsLazyOrderedAndSeriesRenderingReusedWithinRequest(): void
    {
        $this->series = ['id' => 7, 'teaser' => ''];
        ContentFixtures::$content = ['tl_calendar_events:2' => ['Individual'], 'tl_ct_series_templates:7' => ['Series']];
        $resolver = $this->resolver();
        $event = $resolver->resolve(['id' => 2, 'ct_sync_key' => 'key', 'ct_series_template_id' => 7, 'href' => '/event']);
        self::assertSame(0, ContentFixtures::$queries);
        self::assertSame(0, ContentFixtures::$renders);
        self::assertTrue(($event['hasDetails'])());
        self::assertSame(0, ContentFixtures::$renders);
        self::assertSame('<p>Individual</p><p>Series</p>', ($event['details'])());
        self::assertSame('<p>Individual</p><p>Series</p>', ($event['details'])());
        $another = $resolver->resolve(['id' => 3, 'ct_sync_key' => 'another', 'ct_series_template_id' => 7, 'href' => '/another']);
        self::assertSame('<p>Series</p>', ($another['details'])());
        self::assertSame(2, ContentFixtures::$renders);
        $resolver->reset();
        ContentFixtures::$content['tl_ct_series_templates:7'] = ['Changed'];
        $again = $resolver->resolve(['id' => 3, 'ct_sync_key' => 'another', 'ct_series_template_id' => 7, 'href' => '/another']);
        self::assertSame('<p>Changed</p>', ($again['details'])());
    }

    public function testMissingOwnImageUsesCompleteSeriesImageSettings(): void
    {
        $image = tempnam(sys_get_temp_dir(), 'ct-image-');
        try {
            $this->files = ['series-uuid' => basename($image), 'own-uuid' => basename($image)];
            $this->series = ['id' => 7, 'addImage' => '1', 'singleSRC' => 'series-uuid', 'size' => '[320,200]', 'fullsize' => '1', 'floating' => 'above', 'overwriteMeta' => '1', 'alt' => 'Series alt', 'caption' => 'Caption'];
            $resolver = $this->resolver();
            $event = $resolver->resolve(['id' => 2, 'ct_sync_key' => 'key', 'ct_series_template_id' => 7, 'addImage' => '1', 'singleSRC' => 'missing']);
            self::assertSame('series-uuid', $event['singleSRC']);
            self::assertSame('[320,200]', $event['size']);
            self::assertSame('1', $event['fullsize']);
            self::assertSame('Series alt', $event['alt']);
            self::assertSame('Caption', $event['caption']);
            self::assertSame('tl_ct_series_templates', $event['ct_image_owner_table']);
            $own = $resolver->resolve(['id' => 2, 'ct_sync_key' => 'key', 'ct_series_template_id' => 7, 'addImage' => '1', 'singleSRC' => 'own-uuid', 'size' => '[640,480]', 'alt' => 'Own']);
            self::assertSame('own-uuid', $own['singleSRC']);
            self::assertSame('[640,480]', $own['size']);
            self::assertSame('Own', $own['alt']);
            self::assertSame('tl_calendar_events', $own['ct_image_owner_table']);
        } finally {
            unlink($image);
        }
    }

    public function testChurchToolsImageIsLastFallbackAndDoesNotInheritEditorialSettings(): void
    {
        $image = tempnam(sys_get_temp_dir(), 'ct-image-');
        try {
            $this->files = ['ct-uuid' => basename($image), 'series-uuid' => basename($image), 'own-uuid' => basename($image)];
            $this->series = ['id' => 7, 'addImage' => '1', 'singleSRC' => 'series-uuid'];
            $resolver = $this->resolver();
            $base = ['id' => 2, 'ct_sync_key' => 'key', 'ct_series_template_id' => 7, 'ct_image_src' => 'ct-uuid'];
            self::assertSame('own-uuid', $resolver->resolve($base + ['addImage' => '1', 'singleSRC' => 'own-uuid'])['singleSRC']);
            self::assertSame('series-uuid', $resolver->resolve($base)['singleSRC']);

            $this->series = ['id' => 7, 'addImage' => '1', 'singleSRC' => 'missing', 'overwriteMeta' => '1', 'alt' => 'Series alt'];
            $fallback = $this->resolver()->resolve($base + ['addImage' => '1', 'singleSRC' => 'missing', 'size' => '[640,480]', 'fullsize' => '1', 'alt' => 'Own alt']);
            self::assertSame('ct-uuid', $fallback['singleSRC']);
            self::assertSame('1', $fallback['addImage']);
            self::assertSame('ct_image', $fallback['ct_image_owner_table']);
            self::assertSame(2, $fallback['ct_image_owner_id']);
            self::assertNull($fallback['size']);
            self::assertSame('', $fallback['fullsize']);
            self::assertSame('', $fallback['overwriteMeta']);
            self::assertSame('', $fallback['alt']);

            $missing = $this->resolver()->resolve(array_replace($base, ['ct_image_src' => 'missing']));
            self::assertEmpty($missing['addImage']);
        } finally {
            unlink($image);
        }
    }

    public function testFollowingMainRequestDoesNotReuseSeriesContent(): void
    {
        $this->series = ['id' => 7, 'teaser' => ''];
        $requests = new RequestStack();
        $requests->push(Request::create('/first'));
        $resolver = $this->resolver($requests);
        ContentFixtures::$content = ['tl_ct_series_templates:7' => ['First']];
        $first = $resolver->resolve(['id' => 2, 'ct_sync_key' => 'key', 'ct_series_template_id' => 7, 'href' => '/event']);
        self::assertSame('<p>First</p>', ($first['details'])());
        $requests->pop();
        $requests->push(Request::create('/second'));
        ContentFixtures::$content = ['tl_ct_series_templates:7' => ['Second']];
        $second = $resolver->resolve(['id' => 2, 'ct_sync_key' => 'key', 'ct_series_template_id' => 7, 'href' => '/event']);
        self::assertSame('<p>Second</p>', ($second['details'])());
    }

    public function testNoReaderUrlDoesNotProduceDetailsLink(): void
    {
        $event = $this->resolver()->resolve(['id' => 2, 'ct_sync_key' => 'key', 'ct_description' => 'Description', 'href' => null, 'hasReader' => true]);
        self::assertFalse(($event['hasDetails'])());
        self::assertSame('', ($event['details'])());
    }
}

/** Native content loading/rendering is replaced only at the external framework boundary. */
final class ContentFixtures
{
    public static array $content = [];
    public static int $renders = 0;
    public static int $queries = 0;

    public static function findPublishedByPidAndTable(int $id, string $table): array
    {
        ++self::$queries;
        return array_map(static fn ($text) => (object) ['text' => $text], self::$content[$table.':'.$id] ?? []);
    }

    public static function getContentElement(object $element): string
    {
        ++self::$renders;
        return '<p>'.$element->text.'</p>';
    }
}
