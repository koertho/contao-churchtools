<?php

declare(strict_types=1);

use Contao\DataContainer;
use Contao\DC_Table;

$GLOBALS['TL_DCA']['tl_church_tools_entry'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'ptable' => 'tl_church_tools_archive',
        'closed' => true,
        'notEditable' => true,
        'notDeletable' => true,
        'notCopyable' => true,
        'sql' => ['keys' => [
            'id' => 'primary',
            'pid,occurrenceUid' => 'unique',
            'pid,sourceCalendarId' => 'index',
            'pid,startTimestamp' => 'index',
            'pid,startDate' => 'index',
        ]],
    ],
    'list' => [
        'sorting' => ['mode' => DataContainer::MODE_SORTED, 'fields' => ['sourceStart'], 'flag' => DataContainer::SORT_ASC, 'defaultSearchField' => 'title', 'panelLayout' => 'filter;search,limit'],
        'label' => ['fields' => ['title', 'sourceStart'], 'format' => '%s (%s)'],
        'operations' => ['show' => ['href' => 'act=show', 'icon' => 'show.svg']],
    ],
    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'pid' => [
            'foreignKey' => 'tl_church_tools_archive.name',
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy'],
            'sql' => ['type' => 'integer', 'unsigned' => true],
        ],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        // Full byte-exact key, including case and trailing spaces; never a prefix/hash key.
        'occurrenceUid' => ['sql' => ['type' => 'binary', 'length' => 2048]],
        'sourceAppointmentId' => ['sql' => ['type' => 'integer', 'unsigned' => true]],
        'sourceCalendarId' => ['filter' => true, 'sql' => ['type' => 'integer', 'unsigned' => true]],
        'calendarName' => ['sql' => ['type' => 'text']],
        'title' => ['search' => true, 'sql' => ['type' => 'text']],
        'description' => ['sql' => ['type' => 'text', 'length' => 16777215]],
        'allDay' => ['sql' => ['type' => 'boolean']],
        'sourceStart' => ['sql' => ['type' => 'string', 'length' => 64]],
        'sourceEnd' => ['sql' => ['type' => 'string', 'length' => 64]],
        'startTimestamp' => ['sql' => ['type' => 'bigint', 'notnull' => false]],
        'endTimestamp' => ['sql' => ['type' => 'bigint', 'notnull' => false]],
        'startDate' => ['sql' => ['type' => 'date', 'notnull' => false]],
        'endDate' => ['sql' => ['type' => 'date', 'notnull' => false]],
        'tags' => ['sql' => ['type' => 'blob', 'length' => 16777215]],
        // Logical reference only: missing targets remain recorded. No SQL foreign key.
        'contaoEventId' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false]],
    ],
];
