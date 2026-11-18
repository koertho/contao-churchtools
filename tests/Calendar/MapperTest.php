<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Tests\Calendar;

use Koertho\ContaoChurchTools\Calendar\Sync\CalendarEventMapper;
use Koertho\ContaoChurchTools\Calendar\Sync\SourceOccurrence;
use Koertho\ContaoChurchTools\Tests\Calendar\Sync\RemoteFixture;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/Sync/RemoteFixture.php';

final class MapperTest extends TestCase
{
    public function testMapsSourceWithoutOverwritingEditorialFields(): void
    {
        $source = RemoteFixture::row('stable');
        $source['appointment']['base']['repeatId'] = 1;
        $source['appointment']['base']['address'] = ['name' => 'Test church', 'street' => 'Main 1', 'zip' => '12345', 'city' => 'Town', 'addition' => null, 'country' => 'DE', 'geo' => 'PRIVATE_SENTINEL'];
        $fields = (new CalendarEventMapper())->map(SourceOccurrence::fields($source), new \DateTimeZone('Europe/Berlin'));
        self::assertSame(hash('sha256', 'stable'), $fields['ct_sync_key']);
        self::assertSame(110, $fields['ct_calendar_id']);
        self::assertSame(1, $fields['ct_appointment_id']);
        self::assertSame('Test church', $fields['location']);
        self::assertSame('Main 1, 12345 Town, DE', $fields['address']);
        self::assertStringNotContainsString('PRIVATE_SENTINEL', json_encode($fields));
        self::assertSame('1', $fields['addTime']);
        self::assertSame(1, $fields['_repeatId']);
        self::assertSame('2026-09-20', $fields['_localDate']);
        self::assertArrayNotHasKey('teaser', $fields);
        self::assertArrayNotHasKey('singleSRC', $fields);
        self::assertArrayNotHasKey('alias', $fields);
        self::assertArrayNotHasKey('published', $fields);
    }

    public function testLocationCannotBecomeMarkupInNativeTemplates(): void
    {
        $source = RemoteFixture::row();
        $source['appointment']['base']['address'] = ['name' => '<script>alert(1)</script>'];
        $fields = (new CalendarEventMapper())->map(SourceOccurrence::fields($source), new \DateTimeZone('Europe/Berlin'));
        $expected = version_compare(\Contao\CoreBundle\ContaoCoreBundle::getVersion(), '6.0', '<') ? '&lt;script&gt;alert(1)&lt;/script&gt;' : '<script>alert(1)</script>';
        self::assertSame($expected, $fields['location']);
    }

    public function testUidStaysStableWhenDateAndAppointmentIdChange(): void
    {
        $first = SourceOccurrence::fields(RemoteFixture::row('stable'));
        $changed = SourceOccurrence::fields(RemoteFixture::row('stable', '2026-09-21T08:00:00Z', '2026-09-21T09:00:00Z'));
        $changed['sourceAppointmentId'] = 12;
        $mapper = new CalendarEventMapper();
        $before = $mapper->map($first, new \DateTimeZone('Europe/Berlin'));
        $after = $mapper->map($changed, new \DateTimeZone('Europe/Berlin'));
        self::assertSame($before['ct_sync_key'], $after['ct_sync_key']);
        self::assertNotSame($before['ct_hash'], $after['ct_hash']);
    }

    public function testInclusiveAllDayDstLength(): void
    {
        $source = SourceOccurrence::fields(RemoteFixture::row('dst', '2026-03-29', '2026-03-29', true));
        $fields = (new CalendarEventMapper())->map($source, new \DateTimeZone('Europe/Berlin'));
        self::assertSame(23 * 3600, $fields['endTime'] - $fields['startTime'] + 1);
        self::assertSame($fields['startDate'], $fields['endDate']);
    }

    public function testRepeatedClockHourKeepsDistinctInstants(): void
    {
        $source = SourceOccurrence::fields(RemoteFixture::row('dst', '2026-10-25T02:30:00+02:00', '2026-10-25T02:30:00+01:00'));
        $fields = (new CalendarEventMapper())->map($source, new \DateTimeZone('Europe/Berlin'));
        self::assertSame(3600, $fields['endTime'] - $fields['startTime']);
    }

    public function testRejectsUnrepresentableFractionalTime(): void
    {
        $source = SourceOccurrence::fields(RemoteFixture::row('frac', '2026-09-20T08:00:00.000001Z'));
        $this->expectException(\DomainException::class);
        (new CalendarEventMapper())->map($source, new \DateTimeZone('Europe/Berlin'));
    }

    public function testRejectsInvalidLocationFieldTypes(): void
    {
        $source = RemoteFixture::row();
        $source['appointment']['base']['address'] = ['name' => ['invalid']];
        $this->expectException(\InvalidArgumentException::class);
        SourceOccurrence::fields($source);
    }
}
