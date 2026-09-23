<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\EventListener\DataContainer\ChurchToolsArchive;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Koertho\ChurchToolsBundle\Backend\BackendView;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCallback(table: 'tl_church_tools_archive', target: 'fields.syncStatus.input_field')]
final class FieldsSyncStatusInputFieldListener
{
    public function __construct(private readonly Connection $connection, private readonly BackendView $view, private readonly TranslatorInterface $translator)
    {
    }

    public function __invoke(DataContainer $dc): string
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_church_tools_archive WHERE id = ?', [$dc->id]);

        return '<div class="tl_help tl_full">'.($row ? $this->view->status($row) : '').'<p>'.htmlspecialchars((string) ($this->translator->trans('emptyWindow', [], 'church_tools_backend')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p></div>';
    }
}
