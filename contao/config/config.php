<?php

declare(strict_types=1);

use Koertho\ContaoChurchTools\Model\ChurchToolsSeriesTemplateModel;

$GLOBALS['TL_MODELS']['tl_ct_series_templates'] = ChurchToolsSeriesTemplateModel::class;
$GLOBALS['BE_MOD']['content']['ct_series'] = ['tables' => ['tl_ct_series_templates', 'tl_content']];
