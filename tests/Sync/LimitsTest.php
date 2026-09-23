<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Tests\Sync;

use Koertho\ChurchToolsBundle\Api\ApiException;
use Koertho\ChurchToolsBundle\Api\ChurchToolsClient;
use Koertho\ChurchToolsBundle\Api\Transport;
use Koertho\ChurchToolsBundle\Storage\SourceOccurrence;
use Koertho\ChurchToolsBundle\Sync\SnapshotFetcher;
use Koertho\ChurchToolsBundle\Sync\SyncWindow;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

require_once __DIR__.'/RemoteFixture.php';

final class LimitsTest extends TestCase
{
    public function testHttpBodyIsBoundedBeforeJsonDecoding(): void
    {
        $body = (static function () { for ($i = 0; $i < 9; ++$i) { yield str_repeat('x', 1048576); } })();
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('memory budget');
        (new Transport(new MockHttpClient(new MockResponse($body)), 'https://example.org'))->request('GET', '/api/calendars');
    }

    public function testStorageByteLimitBeforeWriting(): void
    {
        $row = RemoteFixture::row();
        $row['appointment']['base']['description'] = str_repeat('x', 16777216);
        $this->expectException(\InvalidArgumentException::class);
        SourceOccurrence::fields($row);
    }

    public function testSnapshotRowBudget(): void
    {
        $remote = new RemoteFixture();
        for ($i = 0; $i <= 10000; ++$i) {
            $remote->rows[] = RemoteFixture::row('uid-'.$i);
        }
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('storage budget');
        (new SnapshotFetcher($remote->client()))->fetch([110], SyncWindow::at(new \DateTimeImmutable('2026-09-17'), 1, 6));
    }

    public function testPaginationHeaderFailsClosed(): void
    {
        $http = new MockHttpClient(new MockResponse('{"data":[]}', ['response_headers' => ['Link: <https://example.org/next>; rel="next"']]));
        $this->expectException(ApiException::class);
        (new ChurchToolsClient(new Transport($http, 'https://example.org'), 'synthetic'))->calendars();
    }

    public function testCrossCalendarUidConflict(): void
    {
        $remote = new RemoteFixture();
        $remote->calendars[] = ['id' => 2, 'name' => 'Other'];
        $remote->rows = [RemoteFixture::row('same'), RemoteFixture::row('same')];
        $remote->rows[1]['appointment']['base']['calendar']['id'] = 2;
        $this->expectException(ApiException::class);
        (new SnapshotFetcher($remote->client()))->fetch([110, 2], SyncWindow::at(new \DateTimeImmutable('2026-09-17'), 1, 6));
    }
}
