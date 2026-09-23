<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Tests\Backend;

use Koertho\ChurchToolsBundle\Backend\CalendarSelection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CalendarSelectionTest extends TestCase
{
    public function testStorageWidgetRoundtrip(): void
    {
        self::assertSame([2, 77], CalendarSelection::decode('[2,77]'));
        self::assertSame('[2,77]', CalendarSelection::fromWidget(serialize(['2', '77', '2'])));
        self::assertSame('[]', CalendarSelection::fromWidget(''));
        self::assertSame([], CalendarSelection::decode(null));
        $blob = fopen('php://memory', 'r+');
        fwrite($blob, '[77]');
        rewind($blob);
        self::assertSame([77], CalendarSelection::decode($blob));
        fclose($blob);
    }

    #[DataProvider('invalidWidget')]
    public function testRejectsInvalidWidgetValues(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CalendarSelection::fromWidget($value);
    }

    public static function invalidWidget(): iterable
    {
        yield [['0']];
        yield [['4294967296']];
        yield [['2x']];
        yield [[[2]]];
        yield [['bad' => 2]];
        yield ['[2]'];
        yield [serialize([new \stdClass()])];
    }

    public function testStorageNeverAcceptsWidgetSerialization(): void
    {
        $this->expectException(\JsonException::class);
        CalendarSelection::decode(serialize([2]));
    }
}
