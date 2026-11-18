<?php

declare(strict_types=1);

$GLOBALS['TL_DCA']['tl_calendar']['palettes']['default'] .= ';{ct_legend},ct_calendar_id,ct_sync_status';
$GLOBALS['TL_DCA']['tl_calendar']['fields']['ct_calendar_id'] = [
    'exclude' => true,
    'inputType' => 'select',
    'default' => null,
    'eval' => ['includeBlankOption' => true, 'chosen' => true, 'doNotCopy' => true, 'tl_class' => 'w50'],
    'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false, 'default' => null],
];
$GLOBALS['TL_DCA']['tl_calendar']['fields']['ct_last_sync'] = ['default' => null, 'eval' => ['doNotCopy' => true], 'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false, 'default' => null]];
$GLOBALS['TL_DCA']['tl_calendar']['fields']['ct_sync_error'] = ['default' => null, 'eval' => ['doNotCopy' => true], 'sql' => ['type' => 'text', 'notnull' => false]];
$GLOBALS['TL_DCA']['tl_calendar']['fields']['ct_sync_counts'] = ['default' => null, 'eval' => ['doNotCopy' => true], 'sql' => ['type' => 'text', 'notnull' => false]];
$GLOBALS['TL_DCA']['tl_calendar']['fields']['ct_sync_status'] = ['eval' => ['doNotSave' => true]];
$GLOBALS['TL_DCA']['tl_calendar']['config']['sql']['keys']['ct_calendar_id'] = 'unique';

foreach ($GLOBALS['TL_DCA']['tl_calendar']['fields'] as $field => &$definition) {
    if (str_starts_with($field, 'ct_')) {
        $definition['eval']['versionize'] = false;
    }
}
unset($field, $definition);
