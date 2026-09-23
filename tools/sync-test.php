<?php

declare(strict_types=1);

use Contao\ManagerBundle\HttpKernel\ContaoKernel;
use Koertho\ChurchToolsBundle\Tests\Sync\SyncAcceptance;
use Symfony\Component\Console\Input\ArrayInput;

$project = $argv[1] ?? '';
$url = getenv('CHURCHTOOLS_TEST_DATABASE_URL') ?: '';
if (!is_file($project.'/vendor/autoload.php') || !preg_match('~/churchtools_step3_[a-z0-9_]+(?:\?|$)~D', $url)) {
    throw new RuntimeException('An explicit isolated matrix project/database is required.');
}
require $project.'/vendor/autoload.php';
require dirname(__DIR__).'/tests/Sync/RemoteFixture.php';
require dirname(__DIR__).'/tests/Sync/SyncAcceptance.php';
require dirname(__DIR__).'/tests/Sync/CommitAcceptance.php';
$kernel = ContaoKernel::fromInput($project, new ArrayInput(['--env' => 'prod', '--no-debug' => true]));
$kernel->boot();
$container = $kernel->getContainer();
$db = $container->get('database_connection');
if (!str_starts_with((string) $db->fetchOne('SELECT DATABASE()'), 'churchtools_step3_')) {
    throw new RuntimeException('Refusing a non-test database.');
}
$container->get('contao.framework')->initialize();
$commitChecks = \Koertho\ChurchToolsBundle\Tests\Sync\CommitAcceptance::run($container);
printf("Real commit regression PASS: %d checks (no outer transaction).\n", $commitChecks);
$db->beginTransaction();
try {
    $count = SyncAcceptance::run($container);
    $application = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
    $command = $application->find('church-tools:sync');
    $output = new \Symfony\Component\Console\Output\BufferedOutput();
    if ($command->run(new ArrayInput(['--archive' => 'invalid']), $output) !== 2) {
        throw new RuntimeException('CLI must reject invalid IDs.');
    }
    $archive = new \Koertho\ChurchToolsBundle\Model\ChurchToolsArchiveModel();
    $archive->name = 'CLI empty selection';
    $archive->setCalendarIds([]);
    $archive->save();
    if ($command->run(new ArrayInput(['--archive' => (string) $archive->id]), $output) !== 0) {
        throw new RuntimeException('Real container CLI service must synchronize empty selection.');
    }
    $count += 2;
    printf("Synchronization acceptance PASS: %d checks; PHP %s; Contao %s\n", $count, PHP_VERSION, Composer\InstalledVersions::getPrettyVersion('contao/core-bundle'));
} finally {
    $db->rollBack();
    $kernel->shutdown();
}
