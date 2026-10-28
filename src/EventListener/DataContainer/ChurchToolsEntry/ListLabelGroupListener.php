<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\EventListener\DataContainer\ChurchToolsEntry;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;

#[AsCallback(table: 'tl_church_tools_entry', target: 'list.label.group')]
final class ListLabelGroupListener
{
    public function __invoke(string $group, int|string $mode, string $field, array $row): string
    {
        if ($field !== 'sourceStart') {
            return $group;
        }

        $month = (int) substr($row['sourceStart'], 5, 2);
        $year = substr($row['sourceStart'], 0, 4);

        return ($GLOBALS['TL_LANG']['MONTHS'][$month - 1] ?? sprintf('%02d', $month)).' '.$year;
    }
}
