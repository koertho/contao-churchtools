<?php

declare(strict_types=1);

use Koertho\ContaoChurchTools\Model\CtSeriesTemplatesModel;

$GLOBALS['TL_MODELS']['tl_ct_series_templates'] = CtSeriesTemplatesModel::class;
$GLOBALS['BE_MOD']['content']['ct_series'] = ['tables' => ['tl_ct_series_templates', 'tl_content']];
