<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Tests;

use Koertho\ChurchToolsBundle\Api\ApiException;
use Koertho\ChurchToolsBundle\Api\Transport;
use Koertho\ChurchToolsBundle\Command\SetupTokenCommand;
use Koertho\ChurchToolsBundle\Setup\TokenSetup;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TokenSetupTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/church-tools-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testSetupVerifiesWithoutSessionAndWritesPrivateFile(): void
    {
        $http = $this->responses(42);
        $command = new CommandTester(new SetupTokenCommand(new TokenSetup(new Transport($http, 'https://example.org'), 'user-secret', 'password-secret')));
        self::assertSame(0, $command->execute(['--output' => $this->directory.'/token']));
        self::assertSame('token-secret', file_get_contents($this->directory.'/token'));
        self::assertSame(0600, fileperms($this->directory.'/token') & 0777);
        foreach (['token-secret', 'password-secret', 'user-secret', 'session-secret'] as $secret) {
            self::assertStringNotContainsString($secret, $command->getDisplay());
        }
        self::assertSame(4, $http->getRequestsCount());
    }

    public function testFailedVerificationNeverWrites(): void
    {
        $command = new CommandTester(new SetupTokenCommand(new TokenSetup(new Transport($this->responses(99), 'https://example.org'), 'user-secret', 'password-secret')));
        self::assertSame(1, $command->execute(['--output' => $this->directory.'/token']));
        self::assertFileDoesNotExist($this->directory.'/token');
        self::assertStringNotContainsString('token-secret', $command->getDisplay());
    }

    public function testExplicitOutputRequiredBeforeNetwork(): void
    {
        $http = new MockHttpClient();
        $command = new CommandTester(new SetupTokenCommand(new TokenSetup(new Transport($http, 'https://example.org'), 'user', 'password')));
        self::assertSame(2, $command->execute([]));
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testExistingSecretCannotBeOverwritten(): void
    {
        file_put_contents($this->directory.'/token', 'existing');
        $http = new MockHttpClient();
        try {
            (new TokenSetup(new Transport($http, 'https://example.org'), 'user', 'password'))->save($this->directory.'/token');
            self::fail('Expected refusal');
        } catch (ApiException) {
            self::assertSame('existing', file_get_contents($this->directory.'/token'));
            self::assertSame(0, $http->getRequestsCount());
        }
    }

    public function testSymlinkCannotBeUsedAsOutput(): void
    {
        symlink($this->directory.'/absent', $this->directory.'/token');
        $this->expectException(ApiException::class);
        (new TokenSetup(new Transport(new MockHttpClient(), 'https://example.org'), 'user', 'password'))->save($this->directory.'/token');
    }

    public static function invalidSetupResponses(): iterable
    {
        yield 'unauthorized' => [[new MockResponse('password-secret', ['http_code' => 401])]];
        yield 'anonymous login' => [[new MockResponse('{"data":{"personId":0}}')]];
        yield 'missing cookie' => [[new MockResponse('{"data":{"personId":42}}')]];
        $login = static fn () => new MockResponse('{"data":{"personId":42}}', ['response_headers' => ['set-cookie: session=session-secret']]);
        yield 'account mismatch' => [[$login(), new MockResponse('{"data":{"id":99}}')]];
        yield 'invalid token' => [[$login(), new MockResponse('{"data":{"id":42}}'), new MockResponse('{"data":null}')]];
        yield 'rejected verification' => [[$login(), new MockResponse('{"data":{"id":42}}'), new MockResponse('{"data":"token-secret"}'), new MockResponse('private token-secret', ['http_code' => 403])]];
    }

    #[DataProvider('invalidSetupResponses')]
    public function testSetupFailureDoesNotPersistOrExposeSecrets(array $responses): void
    {
        $http = new MockHttpClient($responses);
        $command = new CommandTester(new SetupTokenCommand(new TokenSetup(new Transport($http, 'https://example.org'), 'user-secret', 'password-secret')));
        self::assertSame(1, $command->execute(['--output' => $this->directory.'/token']));
        self::assertFileDoesNotExist($this->directory.'/token');
        foreach (['token-secret', 'password-secret', 'session-secret'] as $secret) {
            self::assertStringNotContainsString($secret, $command->getDisplay());
        }
    }

    private function responses(int $verifiedId): MockHttpClient
    {
        $step = 0;

        return new MockHttpClient(function ($method, $url, $options) use (&$step, $verifiedId) {
            ++$step;
            if ($step === 1) {
                self::assertSame('POST', $method);
                self::assertSame('https://example.org/api/login', $url);
                self::assertSame(['username' => 'user-secret', 'password' => 'password-secret'], json_decode($options['body'], true));

                return new MockResponse('{"data":{"personId":42}}', ['response_headers' => ['set-cookie: session=session-secret; Secure; HttpOnly']]);
            }
            if ($step < 4) {
                self::assertContains('Cookie: session=session-secret', $options['headers']);
                self::assertArrayNotHasKey('authorization', $options['normalized_headers']);
            }
            if ($step === 2) {
                self::assertStringContainsString('/api/whoami?only_allow_authenticated=true', $url);

                return new MockResponse('{"data":{"id":42}}');
            }
            if ($step === 3) {
                self::assertSame('https://example.org/api/persons/42/logintoken', $url);

                return new MockResponse('{"data":"token-secret"}');
            }
            self::assertArrayNotHasKey('cookie', $options['normalized_headers']);
            self::assertContains('Authorization: Login token-secret', $options['headers']);

            return new MockResponse(json_encode(['data' => ['id' => $verifiedId]], JSON_THROW_ON_ERROR));
        });
    }
}
