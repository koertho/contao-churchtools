<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Tests\Calendar\Image;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Koertho\ContaoChurchTools\Calendar\Image\CachedImageStore;
use Koertho\ContaoChurchTools\Calendar\Image\ImageImportException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CachedImageStoreTest extends TestCase
{
    private string $project;
    private string $png;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir().'/ct-image-test-'.bin2hex(random_bytes(8));
        mkdir($this->project.'/files', 0755, true);
        mkdir($this->project.'/public/files', 0755, true);
        $this->png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->project, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->project);
    }

    public function testCopiesAndDeduplicatesPublicImageWithoutCredentials(): void
    {
        $requests = 0;
        $http = new MockHttpClient(function ($method, $url, $options) use (&$requests) {
            ++$requests;
            self::assertSame('GET', $method);
            self::assertSame(0, $options['max_redirects']);
            self::assertArrayNotHasKey('authorization', array_change_key_case($options['headers']));
            return new MockResponse($this->png, ['http_code' => 200, 'response_headers' => ['content-type: image/png']]);
        });
        $store = $this->store($http);
        $image = ['id' => 42, 'url' => 'https://ct.example/images/42/abcdefgh12345678'];
        self::assertSame(str_repeat('a', 16), $store->obtain($image));
        self::assertSame(str_repeat('a', 16), $store->obtain($image));
        self::assertSame(2, $requests);
        self::assertFileExists($this->project.'/files/churchtools-import/.public');
        self::assertTrue(is_link($this->project.'/public/files/churchtools-import'));
        self::assertSame(realpath($this->project.'/files/churchtools-import'), realpath($this->project.'/public/files/churchtools-import'));
        $files = glob($this->project.'/files/churchtools-import/*.png');
        self::assertCount(1, $files);
        self::assertSame($this->png, file_get_contents($files[0]));
        self::assertSame(0644, fileperms($files[0]) & 0777);
    }

    #[DataProvider('invalidUrls')]
    public function testRejectsUntrustedUrls(string $url): void
    {
        $http = new MockHttpClient(fn () => self::fail('Unexpected HTTP request'));
        $this->expectException(ImageImportException::class);
        $this->store($http)->obtain(['id' => 42, 'url' => $url]);
    }

    public static function invalidUrls(): iterable
    {
        yield ['https://evil.example/images/42/abcdefgh12345678'];
        yield ['http://ct.example/images/42/abcdefgh12345678'];
        yield ['https://user@ct.example/images/42/abcdefgh12345678'];
        yield ['https://ct.example/images/42/abcdefgh12345678?x=1'];
        yield ['https://ct.example/images/43/abcdefgh12345678'];
        yield ['https://ct.example/images/42/../private'];
    }

    #[DataProvider('invalidResponses')]
    public function testRejectsInvalidResponses(string $body, string $mime, int $status): void
    {
        $http = new MockHttpClient(new MockResponse($body, ['http_code' => $status, 'response_headers' => ['content-type: '.$mime]]));
        $this->expectException(ImageImportException::class);
        $this->store($http)->obtain(['id' => 42, 'url' => 'https://ct.example/images/42/abcdefgh12345678']);
    }

    public static function invalidResponses(): iterable
    {
        yield ['not an image', 'image/png', 200];
        yield [base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='), 'image/jpeg', 200];
        yield [str_repeat('a', 10 * 1024 * 1024 + 1), 'image/png', 200];
        yield ['', 'image/png', 302];
    }

    public function testRefusesToMakeAnExistingUnmanagedDirectoryPublic(): void
    {
        mkdir($this->project.'/files/churchtools-import');
        file_put_contents($this->project.'/files/churchtools-import/editor.txt', 'private');
        $http = new MockHttpClient(new MockResponse($this->png, ['http_code' => 200, 'response_headers' => ['content-type: image/png']]));
        try {
            $this->store($http)->obtain(['id' => 42, 'url' => 'https://ct.example/images/42/abcdefgh12345678']);
            self::fail('The unmanaged directory must be rejected.');
        } catch (ImageImportException) {
            self::assertFileDoesNotExist($this->project.'/files/churchtools-import/.public');
            self::assertSame('private', file_get_contents($this->project.'/files/churchtools-import/editor.txt'));
        }
    }

    public function testReusesPublicUploadDirectorySymlink(): void
    {
        rmdir($this->project.'/public/files');
        symlink($this->project.'/files', $this->project.'/public/files');
        $http = new MockHttpClient(new MockResponse($this->png, ['http_code' => 200, 'response_headers' => ['content-type: image/png']]));
        self::assertSame(str_repeat('a', 16), $this->store($http)->obtain(['id' => 42, 'url' => 'https://ct.example/images/42/abcdefgh12345678']));
        self::assertTrue(is_link($this->project.'/public/files'));
        self::assertSame(realpath($this->project.'/files/churchtools-import'), realpath($this->project.'/public/files/churchtools-import'));
    }

    public function testDoesNotReplaceAnExistingPublicSymlink(): void
    {
        symlink($this->project.'/files', $this->project.'/public/files/churchtools-import');
        $http = new MockHttpClient(new MockResponse($this->png, ['http_code' => 200, 'response_headers' => ['content-type: image/png']]));
        try {
            $this->store($http)->obtain(['id' => 42, 'url' => 'https://ct.example/images/42/abcdefgh12345678']);
            self::fail('A conflicting symlink must be rejected.');
        } catch (ImageImportException) {
            self::assertSame($this->project.'/files', readlink($this->project.'/public/files/churchtools-import'));
        }
    }

    public function testDoesNotReplaceAnExistingPublicDirectory(): void
    {
        mkdir($this->project.'/public/files/churchtools-import');
        file_put_contents($this->project.'/public/files/churchtools-import/editor.txt', 'keep');
        $http = new MockHttpClient(new MockResponse($this->png, ['http_code' => 200, 'response_headers' => ['content-type: image/png']]));
        try {
            $this->store($http)->obtain(['id' => 42, 'url' => 'https://ct.example/images/42/abcdefgh12345678']);
            self::fail('A conflicting directory must be rejected.');
        } catch (ImageImportException) {
            self::assertSame('keep', file_get_contents($this->project.'/public/files/churchtools-import/editor.txt'));
        }
    }

    public function testRejectsSymlinkedDestination(): void
    {
        symlink(sys_get_temp_dir(), $this->project.'/files/churchtools-import');
        $http = new MockHttpClient(new MockResponse($this->png, ['http_code' => 200, 'response_headers' => ['content-type: image/png']]));
        $this->expectException(ImageImportException::class);
        $this->store($http)->obtain(['id' => 42, 'url' => 'https://ct.example/images/42/abcdefgh12345678']);
    }

    private function store(MockHttpClient $http): CachedImageStore
    {
        $adapter = $this->createStub(Adapter::class);
        $adapter->method('__call')->willReturnCallback(static fn ($method, $args) => (object) ['uuid' => str_repeat('a', 16)]);
        $framework = $this->createStub(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($adapter);
        return new CachedImageStore($http, 'https://ct.example', $framework, $this->project, 'files', $this->project.'/public');
    }
}
