<?php

declare(strict_types=1);

$project = $argv[1] ?? '';
$url = getenv('CHURCHTOOLS_TEST_DATABASE_URL') ?: '';
if (!is_file($project.'/vendor/autoload.php') || !preg_match('~/churchtools_step3_[a-z0-9_]+(?:\?|$)~D', $url)) {
    throw new RuntimeException('Explicit isolated matrix database required.');
}
require $project.'/vendor/autoload.php';
$kernel = \Contao\ManagerBundle\HttpKernel\ContaoKernel::fromInput($project, new \Symfony\Component\Console\Input\ArrayInput(['--env'=>'prod','--no-debug'=>true]));
$kernel->boot();
$c = $kernel->getContainer();
$db = $c->get('database_connection');
if (!str_starts_with((string) $db->fetchOne('SELECT DATABASE()'), 'churchtools_step3_')) {
    throw new RuntimeException('Refusing non-test database.');
}
$c->get('contao.framework')->initialize();
$schema = $c->get('contao.doctrine.schema_provider')->createSchema();
$manager = $db->createSchemaManager();
$names = $manager->listTableNames();
// Narrow allowlist, only missing core tables. No alters, drops, migrations or host schema.
foreach (['tl_user','tl_user_group','tl_log','tl_favorites','tl_undo','tl_version','tl_message','tl_files','tl_page','tl_trusted_device','tl_module','tl_job','tl_content','tl_article','tl_member','tl_member_group','tl_image_size','tl_form','tl_theme','tl_layout','tl_search','tl_search_term'] as $name) {
    if (in_array($name, $names, true)) {
        echo $name." already exists; unchanged.\n";
        continue;
    }
    if (!$schema->hasTable($name)) continue;
    foreach ($db->getDatabasePlatform()->getCreateTableSQL($schema->getTable($name)) as $sql) {
        if (($argv[2] ?? '') === '--apply') {
            $db->executeStatement($sql);
            echo 'Created '.$name.".\n";
        } else {
            echo $sql.";\n";
        }
    }
}
$kernel->shutdown();
