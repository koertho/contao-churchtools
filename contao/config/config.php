<?php

declare(strict_types=1);

use Koertho\ChurchToolsBundle\Model\ChurchToolsArchiveModel;
use Koertho\ChurchToolsBundle\Model\ChurchToolsEntryModel;

$GLOBALS['TL_MODELS']['tl_church_tools_archive'] = ChurchToolsArchiveModel::class;
$GLOBALS['TL_MODELS']['tl_church_tools_entry'] = ChurchToolsEntryModel::class;

$GLOBALS['BE_MOD']['church_tools']['church_tools_events'] = [
    'tables' => ['tl_church_tools_archive', 'tl_church_tools_entry'],
];
