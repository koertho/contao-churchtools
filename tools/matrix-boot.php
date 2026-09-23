<?php

declare(strict_types=1);

use Contao\ManagerBundle\HttpKernel\ContaoKernel;
use Koertho\ChurchToolsBundle\ChurchToolsBundle;
use Symfony\Component\Console\Input\ArrayInput;

require __DIR__.'/vendor/autoload.php';
$kernel = ContaoKernel::fromInput(__DIR__, new ArrayInput(['--env' => 'prod', '--no-debug' => true]));
$kernel->boot();
if (!$kernel->getBundle('ChurchToolsBundle') instanceof ChurchToolsBundle) {
    throw new RuntimeException('Manager did not register ChurchToolsBundle.');
}
$container = $kernel->getContainer();
if ($container->getParameter('church_tools.sync.future_months') !== 6 || $container->getParameter('church_tools.sync.past_months') !== 1) {
    throw new RuntimeException('Bundle configuration was not loaded.');
}
$application = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
$command = $application->find('church-tools:setup-token');
$output = new \Symfony\Component\Console\Output\BufferedOutput();
if ($command->run(new ArrayInput([]), $output) !== 2) {
    throw new RuntimeException('Missing explicit output should fail before any network request.');
}
printf("Boot/manager/config/command: PASS; PHP %s; Contao %s; Symfony %s\n", PHP_VERSION, \Composer\InstalledVersions::getPrettyVersion('contao/core-bundle'), \Composer\InstalledVersions::getPrettyVersion('symfony/framework-bundle'));
$kernel->shutdown();
