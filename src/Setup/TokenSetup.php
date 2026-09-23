<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Setup;

use Koertho\ChurchToolsBundle\Api\ApiException;
use Koertho\ChurchToolsBundle\Api\Transport;

final class TokenSetup
{
    public function __construct(
        private readonly Transport $transport,
        #[\SensitiveParameter] private readonly string $username,
        #[\SensitiveParameter] private readonly string $password,
    ) {
    }

    /** Creates a new raw-token file, never replaces an existing file or symlink. */
    public function save(string $destination): void
    {
        if (!str_starts_with($destination, '/') || str_contains($destination, "\0")
            || !is_dir(dirname($destination)) || file_exists($destination) || is_link($destination)) {
            throw new ApiException('Select a new absolute local secret file in an existing private directory.');
        }
        if ($this->username === '' || $this->password === '') {
            throw new ApiException('Configure setup credentials before explicit token setup.');
        }
        [$login, $headers] = $this->transport->request('POST', '/api/login', [
            'json' => ['username' => $this->username, 'password' => $this->password],
        ]);
        $personId = $login['data']['personId'] ?? null;
        if (!is_int($personId) || $personId < 1) {
            throw new ApiException('Token setup login did not identify an account.');
        }
        $cookies = [];
        foreach ($headers['set-cookie'] ?? [] as $cookie) {
            $pair = explode(';', $cookie, 2)[0];
            if (str_contains($pair, '=') && !preg_match('/[\r\n]/', $pair)) {
                $cookies[] = $pair;
            }
        }
        if ($cookies === []) {
            throw new ApiException('Token setup did not receive a login session.');
        }
        $session = ['headers' => ['Cookie' => implode('; ', $cookies)]];
        [$account] = $this->transport->request('GET', '/api/whoami', $session + ['query' => ['only_allow_authenticated' => 'true']]);
        if (($account['data']['id'] ?? null) !== $personId) {
            throw new ApiException('Token setup account verification failed.');
        }
        [$response] = $this->transport->request('GET', '/api/persons/'.$personId.'/logintoken', $session);
        $token = $response['data'];
        if (!is_string($token) || $token === '' || preg_match('/[\x00-\x20\x7f]/', $token)) {
            throw new ApiException('Token setup received an invalid token.');
        }
        // No Cookie header or password: verify independent token authentication.
        [$verified] = $this->transport->request('GET', '/api/whoami', [
            'headers' => ['Authorization' => 'Login '.$token], 'query' => ['only_allow_authenticated' => 'true'],
        ]);
        if (($verified['data']['id'] ?? null) !== $personId) {
            throw new ApiException('Token-only account verification failed.');
        }
        $mask = umask(0077);
        try {
            $file = @fopen($destination, 'x');
        } finally {
            umask($mask);
        }
        if ($file === false) {
            throw new ApiException('Cannot create the selected secret file.');
        }
        try {
            if (@fwrite($file, $token) !== strlen($token) || !@fflush($file)) {
                throw new ApiException('Cannot write the selected secret file.');
            }
        } catch (\Throwable) {
            @unlink($destination);
            throw new ApiException('Cannot write the selected secret file.');
        } finally {
            fclose($file);
        }
    }
}
