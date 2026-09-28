<?php
// Shared test bootstrap, never a public entrypoint.
$project=$argv[1]??'';
$url=getenv('CHURCHTOOLS_TEST_DATABASE_URL')?:'';
if (!is_file($project.'/vendor/autoload.php') || !preg_match('~/churchtools_step3_[a-z0-9_]+(?:\?|$)~D',$url)) throw new RuntimeException('Isolated matrix required.');
if (!is_dir($project.'/system/tmp')) mkdir($project.'/system/tmp', 0775, true);
require $project.'/vendor/autoload.php';
$kernel=\Contao\ManagerBundle\HttpKernel\ContaoKernel::fromInput($project,new \Symfony\Component\Console\Input\ArrayInput(['--env'=>'prod','--no-debug'=>true]));
$kernel->boot();$c=$kernel->getContainer();$db=$c->get('database_connection');
if (!str_starts_with((string)$db->fetchOne('SELECT DATABASE()'),'churchtools_step3_')) throw new RuntimeException('Isolated database required.');
$c->get('contao.framework')->initialize();
$access=new \Koertho\ChurchToolsBundle\EventIntegration\BackendAccess($c->get('security.authorization_checker'));
$actions=new \Koertho\ChurchToolsBundle\EventIntegration\LinkActions($db,$c->get('contao.framework'),$access,$c->get('contao.cache.tag_manager'));
