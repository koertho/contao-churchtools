<?php

declare(strict_types=1);

use Contao\DataContainer;
use Contao\DC_Table;

$GLOBALS['TL_DCA']['tl_church_tools_archive'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'ctable' => ['tl_church_tools_entry'],
        'notCopyable' => true,
        'notDeletable' => true,
        'permissions' => ['create', 'update'],
        'sql' => ['keys' => ['id' => 'primary']],
    ],
    'list' => [
        'sorting' => ['mode' => DataContainer::MODE_SORTED, 'fields' => ['name'], 'flag' => DataContainer::SORT_INITIAL_LETTER_ASC, 'defaultSearchField' => 'name', 'panelLayout' => 'search,limit'],
        'label' => ['fields' => ['name'], 'format' => '%s'],
        'operations' => [
            'entries' => ['href' => 'table=tl_church_tools_entry', 'icon' => 'children.svg'],
            'edit' => ['href' => 'act=edit', 'icon' => 'edit.svg'],
        ],
    ],
    'palettes' => ['default' => '{name_legend},name,calendarIds;{sync_legend},syncStatus'],
    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'name' => [
            'search' => true,
            'exclude' => true,
            'inputType' => 'text',
            'eval' => ['mandatory' => true, 'maxlength' => 255],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        'syncStatus' => ['exclude' => false, 'eval' => ['doNotSave' => true]],
        'calendarIds' => ['exclude' => true, 'inputType' => 'checkbox', 'eval' => ['multiple' => true], 'sql' => ['type' => 'blob', 'notnull' => false]],
        'lastSuccessfulSync' => ['sql' => ['type' => 'bigint', 'unsigned' => true, 'default' => 0]],
        'lastSyncPastMonths' => ['sql' => ['type' => 'smallint', 'unsigned' => true, 'notnull' => false]],
        'lastError' => ['sql' => ['type' => 'text', 'notnull' => false]],
        'removedLinkedEntries' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
    ],
];
