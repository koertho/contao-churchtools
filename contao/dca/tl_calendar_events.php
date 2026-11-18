<?php

declare(strict_types=1);

$nullableInteger = ['type' => 'integer', 'unsigned' => true, 'notnull' => false, 'default' => null];
foreach (['ct_calendar_id', 'ct_appointment_id', 'ct_series_template_id', 'ct_last_sync'] as $field) {
    $GLOBALS['TL_DCA']['tl_calendar_events']['fields'][$field] = ['default' => null, 'eval' => ['doNotCopy' => true], 'sql' => $nullableInteger];
}
foreach (['ct_sync_key', 'ct_hash', 'ct_image_key'] as $field) {
    $GLOBALS['TL_DCA']['tl_calendar_events']['fields'][$field] = ['default' => null, 'eval' => ['doNotCopy' => true], 'sql' => ['type' => 'string', 'length' => 64, 'notnull' => false, 'default' => null, 'platformOptions' => ['collation' => 'ascii_bin']]];
}
$GLOBALS['TL_DCA']['tl_calendar_events']['fields']['ct_image_src'] = ['default' => null, 'eval' => ['doNotCopy' => true], 'sql' => ['type' => 'binary', 'length' => 16, 'fixed' => true, 'notnull' => false, 'default' => null]];
$GLOBALS['TL_DCA']['tl_calendar_events']['fields']['ct_unpublished_by_sync'] = ['default' => '', 'eval' => ['doNotCopy' => true], 'sql' => ['type' => 'string', 'length' => 1, 'default' => '']];
$GLOBALS['TL_DCA']['tl_calendar_events']['fields']['ct_description'] = ['inputType' => 'textarea', 'default' => null, 'eval' => ['readonly' => true, 'doNotCopy' => true, 'tl_class' => 'clr'], 'sql' => ['type' => 'text', 'notnull' => false]];
$GLOBALS['TL_DCA']['tl_calendar_events']['config']['sql']['keys']['pid,ct_sync_key'] = 'unique';
$GLOBALS['TL_DCA']['tl_calendar_events']['config']['sql']['keys']['ct_appointment_id'] = 'index';
$GLOBALS['TL_DCA']['tl_calendar_events']['config']['sql']['keys']['ct_series_template_id'] = 'index';
$GLOBALS['TL_DCA']['tl_calendar_events']['list']['operations']['ct_series'] = ['icon' => 'children.svg'];
unset($nullableInteger, $field);
foreach ($GLOBALS['TL_DCA']['tl_calendar_events']['fields'] as $field => &$definition) {
    if (str_starts_with($field, 'ct_')) {
        $definition['eval']['versionize'] = false;
    }
}
unset($field, $definition);
