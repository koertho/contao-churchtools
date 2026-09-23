<?php

declare(strict_types=1);

use Contao\ManagerBundle\HttpKernel\ContaoKernel;
use Doctrine\DBAL\Schema\Schema;
use Koertho\ChurchToolsBundle\Tests\Storage\StorageAcceptance;
use Symfony\Component\Console\Input\ArrayInput;

// No host environment loader, no migrations, no DROP or TRUNCATE statements.
$project = $argv[1] ?? '';
$apply = ($argv[2] ?? '') === '--apply';
$url = getenv('CHURCHTOOLS_TEST_DATABASE_URL') ?: '';
if (!is_file($project.'/vendor/autoload.php') || !preg_match('~/churchtools_step3_[a-z0-9_]+(?:\?|$)~D', $url)) {
    throw new RuntimeException('Supply an isolated matrix project and CHURCHTOOLS_TEST_DATABASE_URL with database churchtools_step3_*.');
}
require $project.'/vendor/autoload.php';
require dirname(__DIR__).'/tests/Storage/StorageAcceptance.php';
$kernel = ContaoKernel::fromInput($project, new ArrayInput(['--env' => 'prod', '--no-debug' => true]));
$kernel->boot();
$container = $kernel->getContainer();
$connection = $container->get('database_connection');
if (!str_starts_with((string) $connection->fetchOne('SELECT DATABASE()'), 'churchtools_step3_')) {
    throw new RuntimeException('Refusing a non-test database.');
}
$container->get('contao.framework')->initialize();
$fullSchema = $container->get('contao.doctrine.schema_provider')->createSchema();
$names = ['tl_church_tools_archive', 'tl_church_tools_entry', 'tl_calendar', 'tl_calendar_events'];
// A warm schema cache does not populate the process-local DCA globals.
// Load the actual definitions explicitly before testing models and relations.
foreach ($names as $name) {
    \Contao\Controller::loadDataContainer($name);
}
$schema = new Schema(array_map(fn ($name) => clone $fullSchema->getTable($name), $names));
if (!$schema->getTable('tl_church_tools_archive')->hasColumn('lastSyncPastMonths')) {
    throw new RuntimeException('Refresh the isolated project cache before the step-4 schema check.');
}
$manager = $connection->createSchemaManager();
$existing = $manager->listTableNames();
$platform = $connection->getDatabasePlatform();
foreach ($names as $name) {
    if (!in_array($name, $existing, true)) {
        foreach ($platform->getCreateTableSQL($schema->getTable($name)) as $sql) {
            if (!$apply) {
                echo $sql.";\n";
            } else {
                $connection->executeStatement($sql);
            }
        }
    }
}
// The sole step-4 schema extension: add the nullable previous retention value.
// Build only this ADD through Doctrine; never apply arbitrary schema drift.
if (in_array('tl_church_tools_archive', $existing, true)) {
    $before = $manager->introspectTable('tl_church_tools_archive');
    if (!$before->hasColumn('lastSyncPastMonths')) {
        $after = clone $before;
        $after->addColumn('lastSyncPastMonths', 'smallint', ['unsigned' => true, 'notnull' => false]);
        $diff = $manager->createComparator()->compareTables($before, $after);
        foreach ($platform->getAlterTableSQL($diff) as $sql) {
            if ($apply) {
                $connection->executeStatement($sql);
            } else {
                echo $sql.";\n";
            }
        }
    }
}
if (!$apply) {
    echo "PLAN ONLY: review the four isolated tables before --apply.\n";
    exit;
}
$assertSchema = static function () use ($manager, $schema, $platform): void {
    foreach (['tl_church_tools_archive', 'tl_church_tools_entry'] as $name) {
        $diff = $manager->createComparator()->compareTables($manager->introspectTable($name), $schema->getTable($name));
        $sql = $platform->getAlterTableSQL($diff);
        if ($sql !== []) {
            throw new RuntimeException('Schema is not stable: '.implode('; ', $sql));
        }
    }
};
$assertSchema();
$connection->beginTransaction();
try {
    $count = StorageAcceptance::run($connection, $assertSchema);
    printf("Storage acceptance PASS: %d checks; PHP %s; Contao %s; Symfony %s; DBAL %s; DB %s\n", $count, PHP_VERSION,
        Composer\InstalledVersions::getPrettyVersion('contao/core-bundle'),
        Composer\InstalledVersions::getPrettyVersion('symfony/framework-bundle'),
        Composer\InstalledVersions::getPrettyVersion('doctrine/dbal'), $connection->fetchOne('SELECT VERSION()'));
} finally {
    $connection->rollBack();
    $kernel->shutdown();
}
