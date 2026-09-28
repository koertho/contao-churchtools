<?php
require __DIR__.'/event-runtime.php';
foreach (['contao/core-bundle','contao/calendar-bundle','symfony/framework-bundle','doctrine/dbal','phpunit/phpunit'] as $package) echo $package.' '.\Composer\InstalledVersions::getPrettyVersion($package)."\n";
echo 'PHP '.PHP_VERSION.'; DB '.$db->fetchOne('SELECT VERSION()')."\n";
foreach (['tl_church_tools_archive','tl_church_tools_entry','tl_calendar','tl_calendar_events','tl_content','tl_article','tl_page','tl_user','tl_user_group','tl_member','tl_member_group','tl_favorites','tl_version','tl_undo','tl_trusted_device','tl_log','tl_job','tl_theme','tl_layout','tl_form','tl_image_size','tl_search','tl_search_term'] as $table) echo $table.': '.$db->fetchOne('SELECT COUNT(*) FROM '.$table)."\n";
echo 'Triggers: '.$db->fetchOne('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')."\n";
echo 'Extension tables: '.implode(', ', $db->fetchFirstColumn("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'tl_church_tools_%'"))."\n";
$kernel->shutdown();
