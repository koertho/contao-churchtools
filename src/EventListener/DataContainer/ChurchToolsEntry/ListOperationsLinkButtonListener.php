<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\EventListener\DataContainer\ChurchToolsEntry;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Koertho\ChurchToolsBundle\EventIntegration\BackendAccess;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCallback(table: 'tl_church_tools_entry', target: 'list.operations.link.button')]
final class ListOperationsLinkButtonListener
{
    public function __construct(private readonly BackendAccess $access, private readonly UrlGeneratorInterface $router, private readonly TranslatorInterface $translator) {}

    public function __invoke(array $row): string
    {
        try { $this->access->entry($row); } catch (AccessDeniedException) { return ''; }
        return '<a href="'.htmlspecialchars($this->router->generate('church_tools_entry_actions', ['id' => $row['id']]), ENT_QUOTES).'">'.htmlspecialchars($this->translator->trans('actions', [], 'church_tools_backend'), ENT_QUOTES).'</a>';
    }
}
