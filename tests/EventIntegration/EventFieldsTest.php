<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Tests\EventIntegration;

use Koertho\ChurchToolsBundle\EventIntegration\EventFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventFieldsTest extends TestCase
{
    #[DataProvider('days')]
    public function testInclusiveDstDay(string $day, int $hours): void
    {
        $fields = EventFields::dates(['sourceStart'=>$day,'sourceEnd'=>$day,'allDay'=>true], new \DateTimeZone('Europe/Berlin'));
        self::assertSame($hours * 3600, $fields['endTime'] - $fields['startTime'] + 1);
        self::assertSame($fields['startDate'], $fields['endDate']);
        self::assertSame('', $fields['addTime']);
    }
    public static function days(): array { return [['2026-03-29',23],['2026-10-25',25],['2026-09-23',24]]; }

    public function testOffsetInstantsAndLocalDates(): void
    {
        $fields = EventFields::dates(['sourceStart'=>'2026-10-25T02:30:00+02:00','sourceEnd'=>'2026-10-25T02:30:00+01:00','allDay'=>false], new \DateTimeZone('Europe/Berlin'));
        self::assertSame(3600, $fields['endTime']-$fields['startTime']);
        self::assertSame($fields['startDate'], $fields['endDate']);
    }

    #[DataProvider('unrepresentable')]
    public function testCannotSilentlyChangeSemantics(string $start, string $end): void
    {
        $this->expectException(\DomainException::class);
        EventFields::dates(['sourceStart'=>$start,'sourceEnd'=>$end,'allDay'=>false], new \DateTimeZone('Europe/Berlin'));
    }
    public static function unrepresentable(): array { return [
        ['1960-01-01T10:00:00Z','1960-01-01T11:00:00Z'],
        ['2200-01-01T10:00:00Z','2200-01-01T11:00:00Z'],
        ['2026-09-23T10:00:00Z','2026-09-23T10:00:00Z'],
        ['2026-09-23T10:00:00.000001Z','2026-09-23T11:00:00Z'],
        ['2026-09-23T10:00:00Z','2026-09-23T11:00:00.000001Z'],
    ]; }

    public function testPlaintextRemainsLiteral(): void
    {
        self::assertSame('<p>&lt;script&gt;&amp;&quot;&#039;<br>**x**<br><br>&#123;&#123;env::host&#125;&#125;</p>', EventFields::richText("<script>&\"'\r\n**x**\n\n{{env::host}}"));
        self::assertSame('', EventFields::richText(''));
    }
}
