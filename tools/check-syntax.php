<?php

declare(strict_types=1);

$count = 0;
foreach (['src', 'tests', 'tools', 'contao', 'translations'] as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__).'/'.$directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') continue;
        $output = [];
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file->getPathname()).' 2>&1', $output, $exit);
        if ($exit !== 0) {
            fwrite(STDERR, implode("\n", $output)."\n");
            exit($exit);
        }
        ++$count;
    }
}
echo 'PHP syntax: PASS ('.$count." files)\n";
