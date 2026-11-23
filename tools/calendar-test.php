<?php

declare(strict_types=1);

use Contao\ManagerBundle\HttpKernel\ContaoKernel;
use Symfony\Component\Console\Input\ArrayInput;

// This runner never loads the host environment, migrates, alters or drops tables.
$project = $argv[1] ?? '';
$apply = ($argv[2] ?? '') === '--apply';
$url = getenv('CHURCHTOOLS_TEST_DATABASE_URL') ?: '';
if (!is_file($project.'/vendor/autoload.php') || !preg_match('~/churchtools_v2_[a-z0-9_]+(?:\\?|$)~D', $url)) {
    throw new RuntimeException('Supply a matrix project and a dedicated churchtools_v2_* database URL.');
}
require $project.'/vendor/autoload.php';
$kernel = ContaoKernel::fromInput($project, new ArrayInput(['--env'=>'prod', '--no-debug'=>true]));
$kernel->boot();
try {
    $container = $kernel->getContainer();
    $db = $container->get('database_connection');
    if (!str_starts_with((string) $db->fetchOne('SELECT DATABASE()'), 'churchtools_v2_')) {
        throw new RuntimeException('Refusing non-test database.');
    }
    $container->get('contao.framework')->initialize();
    $schema = $container->get('contao.doctrine.schema_provider')->createSchema();
    $manager = $db->createSchemaManager();
    $existing = $manager->listTableNames();
    foreach ($schema->getTables() as $table) {
        if (in_array($table->getName(), $existing, true)) continue;
        foreach ($db->getDatabasePlatform()->getCreateTableSQL($table) as $sql) {
            if ($apply) $db->executeStatement($sql);
            else echo $sql,";\n";
        }
    }
    if (!$apply) {
        echo "PREVIEW ONLY: missing tables in isolated database; use --apply after review.\n";
        exit;
    }
    // Compare extension-owned schema only; native tl_content.jsonData introspects as LONGTEXT on MariaDB.
    foreach (['tl_calendar','tl_calendar_events','tl_ct_series_templates'] as $table) {
        Contao\Controller::loadDataContainer($table);
        $diff = $manager->createComparator()->compareTables($manager->introspectTable($table), $schema->getTable($table));
        if ($db->getDatabasePlatform()->getAlterTableSQL($diff) !== []) throw new RuntimeException('Schema drift: '.$table.' '.implode('; ', $db->getDatabasePlatform()->getAlterTableSQL($diff)));
    }
    $count = 0;
    foreach ([
        ['Calendar/BackendAcceptance.php', 'Koertho\\ContaoChurchTools\\Tests\\Calendar\\BackendAcceptance'],
        ['Calendar/Frontend/FrontendAcceptance.php', 'Koertho\\ContaoChurchTools\\Tests\\Calendar\\Frontend\\FrontendAcceptance'],
    ] as [$file,$class]) {
        $path=dirname(__DIR__).'/tests/'.$file;
        if (!is_file($path)) throw new RuntimeException('Missing acceptance suite: '.$file);
        require_once $path;
        $checks = $class::run($container);
        echo $class,': PASS (',$checks," checks)\n";
        $count += $checks;
    }
    foreach (['tl_calendar', 'tl_calendar_events', 'tl_ct_series_templates', 'tl_content', 'tl_page', 'tl_files', 'tl_version'] as $table) {
        if ((int) $db->fetchOne('SELECT COUNT(*) FROM '.$table) !== 0) {
            throw new RuntimeException('Acceptance fixture cleanup failed: '.$table);
        }
    }
    printf("Calendar V2 acceptance: PASS %d checks; Contao %s; PHP %s; DBAL %s\n", $count, Composer\InstalledVersions::getPrettyVersion('contao/core-bundle'), PHP_VERSION, Composer\InstalledVersions::getPrettyVersion('doctrine/dbal'));
} finally {
    $kernel->shutdown();
}
