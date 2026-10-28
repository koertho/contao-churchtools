<?php

declare(strict_types=1);

use Contao\System;
use Koertho\ChurchToolsBundle\Model\ChurchToolsArchiveModel;
use Koertho\ChurchToolsBundle\Model\ChurchToolsEntryModel;
use Symfony\Component\HttpFoundation\Request;

$GLOBALS['TL_MODELS']['tl_church_tools_archive'] = ChurchToolsArchiveModel::class;
$GLOBALS['TL_MODELS']['tl_church_tools_entry'] = ChurchToolsEntryModel::class;

$GLOBALS['BE_MOD']['church_tools']['church_tools_events'] = [
    'tables' => ['tl_church_tools_archive', 'tl_church_tools_entry'],
];

if (System::getContainer()->get('contao.routing.scope_matcher')->isBackendRequest(System::getContainer()->get('request_stack')->getCurrentRequest() ?? Request::create(''))) {
    $GLOBALS['TL_CSS'][] = 'bundles/churchtools/backend.css|static';
}
