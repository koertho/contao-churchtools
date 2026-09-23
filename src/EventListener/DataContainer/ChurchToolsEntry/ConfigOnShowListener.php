<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\EventListener\DataContainer\ChurchToolsEntry;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Koertho\ChurchToolsBundle\Backend\BackendView;

#[AsCallback(table: 'tl_church_tools_entry', target: 'config.onshow')]
final class ConfigOnShowListener
{
    public function __construct(private readonly BackendView $view)
    {
    }

    public function __invoke(array $data, array $row): array
    {
        return $this->view->details($row);
    }
}
