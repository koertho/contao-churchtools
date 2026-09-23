<?php

declare(strict_types=1);

// Run with DDEV locally. Every target gets an independent Composer project/cache.
$target = $argv[1] ?? '';
$directory = $argv[2] ?? '';
if (!in_array($target, ['5.7.*', '6.0.*'], true) || !str_starts_with($directory, '/') || file_exists($directory.'/composer.json')) {
    throw new RuntimeException('Supply 5.7.* or 6.0.* and a new absolute project directory.');
}
@mkdir($directory.'/config', 0700, true);
@mkdir($directory.'/public', 0700, true);
$package = dirname(__DIR__);
file_put_contents($directory.'/composer.json', json_encode([
    'name' => 'koertho/churchtools-matrix', 'type' => 'project', 'license' => 'proprietary',
    'require' => ['php' => '^8.4', 'contao/manager-bundle' => $target, 'contao/calendar-bundle' => $target, 'koertho/contao-churchtools' => '@dev', 'phpunit/phpunit' => '^12.4'],
    'repositories' => [['type' => 'path', 'url' => $package, 'options' => ['symlink' => true, 'versions' => ['koertho/contao-churchtools' => 'dev-main']]]],
    'config' => ['allow-plugins' => ['contao/manager-plugin' => true, 'contao-components/installer' => true, 'php-http/discovery' => false]],
    'extra' => ['public-dir' => 'public'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
file_put_contents($directory.'/config/config.yaml', "parameters:\n  matrix_database_url: 'mysql://unused:unused@127.0.0.1/unused?serverVersion=10.11.0-MariaDB'\nframework:\n  secret: matrix-only-not-a-real-secret\ndoctrine:\n  dbal:\n    url: '%env(default:matrix_database_url:CHURCHTOOLS_TEST_DATABASE_URL)%'\nchurch_tools:\n  instance_url: 'https://example.org'\n  token: 'synthetic-matrix-token'\n");
copy(__DIR__.'/matrix-boot.php', $directory.'/matrix-boot.php');
echo "Isolated matrix project created.\n";
