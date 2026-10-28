<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\EventListener\DataContainer\ChurchToolsEntry;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\Image;
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
        $label = htmlspecialchars($this->translator->trans('actions', [], 'church_tools_backend'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $url = htmlspecialchars($this->router->generate('church_tools_entry_actions', ['id' => $row['id']]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<a href="'.$url.'" title="'.$label.'" aria-label="'.$label.'" data-contao--tooltips-target="tooltip">'.Image::getHtml('bundles/churchtools/icons/calendar.svg', '', 'width="16" height="16"').'</a>';
    }
}
