<?php

declare(strict_types=1);

use Contao\Controller;
use Contao\DataContainer;
use Contao\DC_Table;

Controller::loadDataContainer('tl_calendar_events');
$GLOBALS['TL_DCA']['tl_ct_series_templates'] = [
    'config' => ['dataContainer' => DC_Table::class, 'ctable' => ['tl_content'], 'closed' => true, 'notCopyable' => true, 'enableVersioning' => true, 'sql' => ['keys' => ['id' => 'primary', 'ct_appointment_id' => 'unique']]],
    'list' => [
        'sorting' => ['mode' => DataContainer::MODE_SORTED, 'fields' => ['title'], 'flag' => DataContainer::SORT_INITIAL_LETTER_ASC, 'panelLayout' => 'search,limit'],
        'label' => ['fields' => ['title'], 'format' => '%s'],
        'operations' => ['edit', 'children', 'delete', 'show'],
    ],
    'palettes' => ['__selector__' => ['addImage', 'overwriteMeta'], 'default' => '{title_legend},title;{details_legend},teaser;{image_legend},addImage'],
    'subpalettes' => ['addImage' => 'singleSRC,fullsize,size,floating,overwriteMeta', 'overwriteMeta' => 'alt,imageTitle,imageUrl,caption'],
    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'ct_appointment_id' => ['eval' => ['versionize' => false], 'sql' => ['type' => 'integer', 'unsigned' => true]],
        'title' => ['inputType' => 'text', 'search' => true, 'eval' => ['readonly' => true, 'versionize' => false, 'maxlength' => 255], 'sql' => ['type' => 'string', 'length' => 255, 'default' => '']],
        'teaser' => ['exclude' => true, 'inputType' => 'textarea', 'eval' => ['rte' => 'tinyMCE', 'basicEntities' => true, 'tl_class' => 'clr'], 'sql' => ['type' => 'text', 'notnull' => false]],
    ],
];
$imageFields = ['addImage', 'singleSRC', 'fullsize', 'size', 'floating', 'overwriteMeta', 'alt', 'imageTitle', 'imageUrl', 'caption'];
foreach ($imageFields as $field) {
    $definition = $GLOBALS['TL_DCA']['tl_calendar_events']['fields'][$field];
    unset($definition['options_callback'], $definition['save_callback'], $definition['load_callback']);
    $definition['exclude'] = true;
    // Native widgets/evaluation, with Doctrine schema definitions for this new table.
    $definition['sql'] = match ($field) {
        'addImage', 'fullsize', 'overwriteMeta' => ['type' => 'boolean', 'default' => false],
        'singleSRC' => ['type' => 'binary', 'length' => 16, 'fixed' => true, 'notnull' => false],
        'size' => ['type' => 'string', 'length' => 255, 'default' => '', 'platformOptions' => ['collation' => 'ascii_bin']],
        'floating' => ['type' => 'string', 'length' => 32, 'default' => 'above'],
        default => ['type' => 'text', 'notnull' => false],
    };
    $GLOBALS['TL_DCA']['tl_ct_series_templates']['fields'][$field] = $definition;
}
unset($imageFields, $field, $definition);
