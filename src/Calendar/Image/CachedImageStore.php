<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Calendar\Image;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Util\SymlinkUtil;
use Contao\Dbafs;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class CachedImageStore implements CachedImageStoreInterface
{
    private const MAX_BYTES = 10 * 1024 * 1024;
    private const MAX_PIXELS = 40_000_000;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $baseUrl,
        private readonly ContaoFramework $framework,
        private readonly string $projectDir,
        private readonly string $uploadPath,
        private readonly string $webDir,
    ) {
    }

    public function obtain(array $image): string
    {
        $url = $image['url'] ?? null;
        $id = $image['id'] ?? null;
        $base = parse_url($this->baseUrl);
        $parts = is_string($url) ? parse_url($url) : false;
        if (!is_int($id) || $id < 1 || !is_array($base) || !is_array($parts)
            || strtolower($base['scheme'] ?? '') !== 'https' || strtolower($parts['scheme'] ?? '') !== 'https'
            || empty($base['host']) || strtolower($base['host']) !== strtolower($parts['host'] ?? '')
            || ($base['port'] ?? 443) !== ($parts['port'] ?? 443)
            || isset($base['user']) || isset($base['pass']) || isset($base['query']) || isset($base['fragment']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || !preg_match('~^/images/([1-9][0-9]*)/[A-Za-z0-9_-]{16,128}$~D', $parts['path'] ?? '', $matches)
            || $matches[1] !== (string) $id
        ) {
            throw new ImageImportException('Invalid ChurchTools image reference.');
        }

        try {
            $response = $this->http->request('GET', $url, [
                'max_redirects' => 0,
                'timeout' => 20,
                'max_duration' => 60,
                'headers' => ['Accept' => 'image/png, image/jpeg, image/gif, image/webp'],
            ]);
            if (200 !== $response->getStatusCode()) {
                throw new ImageImportException('ChurchTools image is not publicly accessible.');
            }
            $headers = $response->getHeaders(false);
            $mime = strtolower(trim(explode(';', $headers['content-type'][0] ?? '')[0]));
            if (isset($headers['content-length'][0]) && (int) $headers['content-length'][0] > self::MAX_BYTES) {
                throw new ImageImportException('ChurchTools image is too large.');
            }
            $bytes = '';
            foreach ($this->http->stream($response) as $chunk) {
                $content = $chunk->getContent();
                if (strlen($content) > self::MAX_BYTES - strlen($bytes)) {
                    $response->cancel();
                    throw new ImageImportException('ChurchTools image is too large.');
                }
                $bytes .= $content;
            }
        } catch (ImageImportException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new ImageImportException('Unable to download ChurchTools image.');
        }

        $info = @getimagesizefromstring($bytes);
        $types = [IMAGETYPE_PNG => ['image/png', 'png'], IMAGETYPE_JPEG => ['image/jpeg', 'jpg'], IMAGETYPE_GIF => ['image/gif', 'gif'], IMAGETYPE_WEBP => ['image/webp', 'webp']];
        if (false === $info || !isset($types[$info[2]]) || $mime !== $types[$info[2]][0]
            || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS
        ) {
            throw new ImageImportException('Invalid ChurchTools image data.');
        }

        try {
            $path = $this->store($bytes, $types[$info[2]][1]);
            $this->framework->initialize();
            $file = $this->framework->getAdapter(Dbafs::class)->addResource($path);
            $uuid = $file->uuid ?? null;
            if (!is_string($uuid) || strlen($uuid) !== 16) {
                throw new ImageImportException('Unable to register ChurchTools image.');
            }
            return $uuid;
        } catch (ImageImportException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new ImageImportException('Unable to store ChurchTools image.');
        }
    }

    private function store(string $bytes, string $extension): string
    {
        if (!preg_match('~^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$~D', $this->uploadPath)
            || in_array('..', explode('/', $this->uploadPath), true)
            || in_array('.', explode('/', $this->uploadPath), true)
        ) {
            throw new ImageImportException('Invalid Contao upload path.');
        }
        $project = realpath($this->projectDir);
        $upload = realpath($this->projectDir.'/'.$this->uploadPath);
        if (false === $project || false === $upload || !is_dir($upload) || !str_starts_with($upload.'/', $project.'/')) {
            throw new ImageImportException('Invalid Contao upload directory.');
        }
        $directory = $upload.'/churchtools-import';
        if (is_link($directory)) {
            throw new ImageImportException('Invalid ChurchTools image directory.');
        }
        if (!file_exists($directory)) {
            if (!@mkdir($directory, 0755) || false === @file_put_contents($directory.'/.ct-managed', '') || false === @file_put_contents($directory.'/.public', '')) {
                throw new ImageImportException('Unable to create ChurchTools image directory.');
            }
        }
        if (!is_dir($directory) || realpath($directory) !== $directory || is_link($directory.'/.ct-managed') || !is_file($directory.'/.ct-managed') || is_link($directory.'/.public') || !is_file($directory.'/.public')) {
            throw new ImageImportException('ChurchTools image directory is not managed or public.');
        }
        $this->publishDirectory($project, $directory);
        $name = hash('sha256', $bytes).'.'.$extension;
        $target = $directory.'/'.$name;
        if (is_link($target)) {
            throw new ImageImportException('Invalid ChurchTools image file.');
        }
        if (!file_exists($target)) {
            $tmp = tempnam($directory, '.ct-');
            if (false === $tmp) {
                throw new ImageImportException('Unable to create ChurchTools image file.');
            }
            try {
                if (strlen($bytes) !== file_put_contents($tmp, $bytes) || !chmod($tmp, 0644) || (!@link($tmp, $target) && !is_file($target))) {
                    throw new ImageImportException('Unable to save ChurchTools image file.');
                }
            } finally {
                @unlink($tmp);
            }
        }
        if (is_link($target) || !is_file($target) || hash_file('sha256', $target) !== hash('sha256', $bytes)) {
            throw new ImageImportException('Invalid existing ChurchTools image file.');
        }
        return $this->uploadPath.'/churchtools-import/'.$name;
    }
    private function publishDirectory(string $project, string $directory): void
    {
        $web = realpath($this->webDir);
        if (false === $web || !is_dir($web)) {
            throw new ImageImportException('Invalid Contao web directory.');
        }

        $parent = $web;
        $source = $project;
        foreach (explode('/', $this->uploadPath) as $segment) {
            $parent .= '/'.$segment;
            $source .= '/'.$segment;
            if (is_link($parent)) {
                if (realpath($parent) !== realpath($source)) {
                    throw new ImageImportException('Conflicting public image directory.');
                }
            } elseif (!is_dir($parent) && (file_exists($parent) || !@mkdir($parent, 0755))) {
                throw new ImageImportException('Unable to create public image directory.');
            }
        }

        $link = $parent.'/churchtools-import';
        if (is_link($link) || file_exists($link)) {
            if (realpath($link) !== $directory) {
                throw new ImageImportException('Conflicting public image directory.');
            }
            return;
        }

        SymlinkUtil::symlink($directory, $link, $project);
        if (realpath($link) !== $directory) {
            throw new ImageImportException('Unable to publish ChurchTools image directory.');
        }
    }

}
