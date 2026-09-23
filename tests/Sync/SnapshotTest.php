<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Tests\Sync;

use Koertho\ChurchToolsBundle\Sync\SnapshotFetcher;
use Koertho\ChurchToolsBundle\Sync\SyncWindow;
use Koertho\ChurchToolsBundle\Storage\SourceOccurrence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/RemoteFixture.php';

final class SnapshotTest extends TestCase
{
    public function testMonthClampingAndIntervals(): void
    {
        $window = SyncWindow::at(new \DateTimeImmutable('2026-08-31T12:00:00Z'), 6, 6);
        self::assertSame('2026-02-28', $window->from->format('Y-m-d'));
        self::assertSame('2027-02-28', $window->to->format('Y-m-d'));
        $window = SyncWindow::at(new \DateTimeImmutable('2026-09-17T12:00:00Z'), 1, 6);
        foreach ([
            ['2026-08-16', '2026-08-17', true, null],
            ['2026-08-15', '2026-08-16', true, 'retention'],
            ['2027-03-17', '2027-03-18', true, 'window'],
            ['2026-08-16T23:00:00Z', '2026-08-17T00:00:00Z', false, 'retention'],
            ['2026-08-17T00:00:00Z', '2026-08-17T00:00:00Z', false, null],
            ['2027-03-17T00:00:00Z', '2027-03-17T01:00:00Z', false, 'window'],
            ['2026-08-16T23:30:00-02:00', '2026-08-17T01:00:00-02:00', false, null],
        ] as [$start, $end, $allDay, $reason]) {
            self::assertSame($reason, $window->outside(SourceOccurrence::fields(RemoteFixture::row('edge', $start, $end, $allDay))));
        }
    }

    public static function failures(): iterable
    {
        foreach (['missing', 'lost-after-fetch', 'duplicate-calendar', '401', '403', '500', 'malformed', 'partial', 'pagination', 'next', 'empty', 'split-mismatch'] as $failure) {
            yield $failure => [$failure];
        }
    }

    #[DataProvider('failures')]
    public function testRejectsAmbiguousSnapshots(string $failure): void
    {
        $remote = new RemoteFixture();
        $remote->rows = [RemoteFixture::row()];
        $remote->failure = $failure;
        $this->expectException(\Koertho\ChurchToolsBundle\Api\ApiException::class);
        (new SnapshotFetcher($remote->client()))->fetch([110], SyncWindow::at(new \DateTimeImmutable('2026-09-17'), 1, 6));
    }

    public function testInternalUidsAndAllowlistAndBoundaryDeduplication(): void
    {
        $remote = new RemoteFixture();
        $remote->rows = [RemoteFixture::row('public', '2026-09-17T08:00:00Z', '2026-09-17T09:00:00Z'), RemoteFixture::row('internal')];
        $remote->rows[1]['appointment']['base']['isInternal'] = true;
        $result = (new SnapshotFetcher($remote->client()))->fetch([110], SyncWindow::at(new \DateTimeImmutable('2026-09-17'), 1, 6));
        self::assertCount(2, $result);
        self::assertTrue($result['uid:internal']['internal']);
        self::assertStringNotContainsString('PRIVATE_SENTINEL', json_encode($result));
    }

    public function testLateInvalidUidRefusesWholeSnapshot(): void
    {
        $remote = new RemoteFixture();
        $remote->rows = [RemoteFixture::row(), RemoteFixture::row(str_repeat('x', 2049))];
        $this->expectException(\InvalidArgumentException::class);
        (new SnapshotFetcher($remote->client()))->fetch([110], SyncWindow::at(new \DateTimeImmutable('2026-09-17'), 1, 6));
    }
}
