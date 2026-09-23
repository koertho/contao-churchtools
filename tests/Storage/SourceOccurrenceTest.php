<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Tests\Storage;

use Koertho\ChurchToolsBundle\Storage\SourceOccurrence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SourceOccurrenceTest extends TestCase
{
    #[DataProvider('invalidValues')]
    public function testRejectsInvalidSource(array $patch): void
    {
        $row = [
            'appointment' => ['base' => ['id' => 1, 'calendar' => ['id' => 1, 'name' => 'Test'], 'title' => 'Test', 'description' => '', 'allDay' => false, 'isInternal' => false],
                'calculated' => ['iCalUid' => 'test', 'startDate' => '2026-03-29T01:30:00+01:00', 'endDate' => '2026-03-29T03:30:00+02:00']],
            'tags' => [],
        ];
        $this->expectException(\InvalidArgumentException::class);
        SourceOccurrence::fields(array_replace_recursive($row, $patch));
    }

    public static function invalidValues(): iterable
    {
        foreach ([true, null, 0, 'false'] as $value) {
            yield [['appointment' => ['base' => ['isInternal' => $value]]]];
        }
        foreach (['', str_repeat('x', 2049)] as $value) {
            yield [['appointment' => ['calculated' => ['iCalUid' => $value]]]];
        }
        foreach (['2026-02-30T00:00:00Z', '2026-03-29T00:00:00', '2026-03-29', 'tomorrow', '2026-03-29T00:00:00.1234567Z'] as $value) {
            yield [['appointment' => ['calculated' => ['startDate' => $value]]]];
        }
        yield [['appointment' => ['calculated' => ['endDate' => '2026-03-28T00:00:00Z']]]];
        yield [['appointment' => ['base' => ['id' => -1]]]];
        yield [['tags' => [['id' => 1, 'name' => 'Test', 'description' => [], 'color' => 'basic']]]];
    }
}
