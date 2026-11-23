<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\EventListener\DataContainer\CtSeriesTemplates;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Koertho\ContaoChurchTools\Calendar\Backend\SeriesDeletionProtection;

#[AsCallback(table: 'tl_ct_series_templates', target: 'config.ondelete')]
final class ConfigOnDeleteListener
{
    public function __construct(private readonly SeriesDeletionProtection $protection) {}

    public function __invoke(DataContainer $dc): void
    {
        $this->protection->protect((int) $dc->id);
    }
}
