<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\EventListener\DataContainer\ChurchToolsArchive;

use Contao\CoreBundle\DataContainer\RecordLabel;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Koertho\ChurchToolsBundle\Backend\BackendView;

#[AsCallback(table: 'tl_church_tools_archive', target: 'list.label.label')]
final class ListLabelLabelListener
{
    public function __construct(private readonly BackendView $view)
    {
    }

    public function __invoke(array $row): string|RecordLabel
    {
        $html = htmlspecialchars((string) ($row['name']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'<div class="tl_gray">'.$this->view->status($row).'</div>';

        // Contao 6 treats plain callback strings as text; 5.7 expects HTML.
        return class_exists(RecordLabel::class) ? RecordLabel::fromHtml($html) : $html;
    }
}
