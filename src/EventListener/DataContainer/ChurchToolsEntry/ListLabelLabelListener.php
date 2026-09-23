<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\EventListener\DataContainer\ChurchToolsEntry;

use Contao\CoreBundle\DataContainer\RecordLabel;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Koertho\ChurchToolsBundle\Backend\BackendView;

#[AsCallback(table: 'tl_church_tools_entry', target: 'list.label.label')]
final class ListLabelLabelListener
{
    public function __construct(private readonly BackendView $view)
    {
    }

    public function __invoke(array $row): string|RecordLabel
    {
        $html = htmlspecialchars((string) ($row['title']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').' <span class="tl_gray">'.htmlspecialchars((string) ($row['sourceStart'].' – '.$row['sourceEnd'].' | '.$row['calendarName'].' (ID '.$row['sourceCalendarId'].') | '.$this->view->linkedState($row)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</span>';

        // Contao 6 treats plain callback strings as text; 5.7 expects HTML.
        return class_exists(RecordLabel::class) ? RecordLabel::fromHtml($html) : $html;
    }
}
