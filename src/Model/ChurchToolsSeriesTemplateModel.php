<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Model;

use Contao\Model;
use Contao\Model\MetadataTrait;

final class ChurchToolsSeriesTemplateModel extends Model
{
    use MetadataTrait;

    protected static $strTable = 'tl_ct_series_templates';
}
