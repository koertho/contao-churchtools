<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Tests\Frontend;

use Koertho\ChurchToolsBundle\Frontend\AppointmentViews;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\Translator;

final class AppointmentViewsTest extends TestCase
{
    private function views(): AppointmentViews
    {
        return new AppointmentViews(new Translator('en'));
    }

    private function row(string $title, string $start, string $end, bool $allDay = false, ?string $url = null): array
    {
        $zone = new \DateTimeZone('Europe/Berlin');
        return ['title'=>$title, 'start'=>new \DateTimeImmutable($start, $zone), 'end'=>new \DateTimeImmutable($end, $zone),
            'allDay'=>$allDay, 'description'=>'', 'descriptionIsHtml'=>false, 'url'=>$url];
    }

    public function testListGroupsAVisibleSpanOnceAndFormatsPointAndDst(): void
    {
        $from = new \DateTimeImmutable('2026-03-29 00:00:00', new \DateTimeZone('Europe/Berlin'));
        $groups = $this->views()->listGroups([
            $this->row('Span', '2026-03-28 20:00', '2026-03-30 10:00'),
            $this->row('Point', '2026-03-29 03:30', '2026-03-29 03:30'),
            $this->row('Next', '2026-03-30 09:00', '2026-03-30 10:00'),
        ], $from, 'de');
        self::assertCount(2, $groups);
        self::assertSame(['Span', 'Point'], array_column($groups[0]['items'], 'title'));
        self::assertSame('03:30', $groups[0]['items'][1]['when']);
        self::assertSame(['Next'], array_column($groups[1]['items'], 'title'));
        $english = $this->views()->listGroups([$this->row('Point', '2026-03-29 03:30', '2026-03-29 03:30')], $from, 'en');
        self::assertStringContainsString('March 29', $english[0]['date']);
        self::assertStringContainsString('AM', $english[0]['items'][0]['when']);
        self::assertSame([], $this->views()->listGroups([], $from, 'en'));
    }

    public function testCalendarSpansMonthBoundariesAndNavigation(): void
    {
        $month = new \DateTimeImmutable('2026-03-01', new \DateTimeZone('Europe/Berlin'));
        $request = Request::create('/appointments?search=a%26b&lang=de&ct_month_12=2026-03');
        $calendar = $this->views()->calendar([
            $this->row('Opening', '2026-02-28', '2026-03-02 23:59:59', true),
            $this->row('Closing', '2026-03-31 20:00', '2026-04-02 09:00'),
        ], $month, $month, 'de', $request, 'ct_month_12');
        $days = array_values(array_filter(array_merge(...$calendar['weeks'])));
        self::assertCount(31, $days);
        self::assertCount(1, $days[0]['items']);
        self::assertSame('Opening', $days[0]['items'][0]['title']);
        self::assertSame('Opening', $days[1]['items'][0]['title']);
        self::assertSame('Closing', $days[30]['items'][0]['title']);
        self::assertCount(6, $calendar['weeks']);
        self::assertCount(7, $calendar['weeks'][0]);
        self::assertStringContainsString('search=a%26b', $calendar['previous']);
        self::assertStringContainsString('ct_month_12=2026-02', $calendar['previous']);
        self::assertStringContainsString('ct_month_12=2026-04', $calendar['next']);
        $empty = $this->views()->calendar([], $month, $month->modify('+2 months'), 'en', $request, 'ct_month_12');
        self::assertFalse($empty['hasItems']);
        self::assertNull($empty['previous']);
        self::assertNull($empty['next']);
        self::assertStringContainsString('March 2026', $empty['heading']);
    }

    public function testCalendarAnchorsCurrentMonthAndTraversesGapsOnlyWithinEffectiveBounds(): void
    {
        $current = new \DateTimeImmutable('2026-03-01', new \DateTimeZone('Europe/Berlin'));
        $request = Request::create('/appointments?other=kept');
        $views = $this->views();
        $onlyCurrent = $views->calendar([$this->row('Now', '2026-03-18 09:00', '2026-03-18 10:00')], $current, $current, 'en', $request, 'ct_month_12');
        self::assertTrue($onlyCurrent['hasItems']);
        self::assertNull($onlyCurrent['previous']);
        self::assertNull($onlyCurrent['next']);

        $rows = [$this->row('Past', '2026-01-15', '2026-01-15'), $this->row('Future', '2026-06-04', '2026-06-04')];
        $gap = $views->calendar($rows, $current, $current, 'en', $request, 'ct_month_12');
        self::assertFalse($gap['hasItems']);
        self::assertStringContainsString('ct_month_12=2026-02', $gap['previous']);
        self::assertStringContainsString('ct_month_12=2026-04', $gap['next']);
        $before = $views->calendar($rows, $current, $current->modify('-5 months'), 'en', $request, 'ct_month_12');
        self::assertStringContainsString('January 2026', $before['heading']);
        self::assertTrue($before['hasItems']);
        self::assertNull($before['previous']);
        $after = $views->calendar($rows, $current, $current->modify('+5 months'), 'en', $request, 'ct_month_12');
        self::assertStringContainsString('June 2026', $after['heading']);
        self::assertTrue($after['hasItems']);
        self::assertNull($after['next']);
    }

    public function testCalendarMonthBoundsIncludeTheLastLocalDayOfSpans(): void
    {
        $current = new \DateTimeImmutable('2026-03-01', new \DateTimeZone('Europe/Berlin'));
        $request = Request::create('/appointments');
        $rows = [$this->row('Edge span', '2026-02-28', '2026-04-01 23:59:59', true)];
        $first = $this->views()->calendar($rows, $current, $current->modify('-1 month'), 'en', $request, 'month');
        self::assertNull($first['previous']);
        self::assertTrue($first['hasItems']);
        $last = $this->views()->calendar($rows, $current, $current->modify('+1 month'), 'en', $request, 'month');
        self::assertNull($last['next']);
        $days = array_values(array_filter(array_merge(...$last['weeks'])));
        self::assertSame('Edge span', $days[0]['items'][0]['title']);
        self::assertSame([], $days[1]['items']);
        $lastRepresentable = new \DateTimeImmutable('9999-12-01', new \DateTimeZone('Europe/Berlin'));
        $offsetEdge = $this->views()->calendar([
            $this->row('Offset edge', '9999-12-30T12:00:00+00:00', '9999-12-31T23:00:00+00:00'),
        ], $lastRepresentable, $lastRepresentable, 'en', $request, 'month');
        self::assertTrue($offsetEdge['hasItems']);
        self::assertNull($offsetEdge['next']);
    }

    public function testOnlySafeDetailUrlsAreReturned(): void
    {
        $from = new \DateTimeImmutable('2026-09-25', new \DateTimeZone('Europe/Berlin'));
        foreach (['javascript:alert(1)', '//evil.invalid', 'https://', "https://example.org/\nbad"] as $unsafe) {
            $item = $this->views()->listGroups([$this->row('Unsafe', '2026-09-25', '2026-09-25', url:$unsafe)], $from, 'en')[0]['items'][0];
            self::assertNull($item['url']);
        }
        $item = $this->views()->listGroups([$this->row('Safe', '2026-09-25', '2026-09-25', url:'/reader/event.html')], $from, 'en')[0]['items'][0];
        self::assertSame('/reader/event.html', $item['url']);
    }
}
