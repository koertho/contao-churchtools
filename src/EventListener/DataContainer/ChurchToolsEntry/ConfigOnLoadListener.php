<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\EventListener\DataContainer\ChurchToolsEntry;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\Input;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[AsCallback(table: 'tl_church_tools_entry', target: 'config.onload')]
final class ConfigOnLoadListener
{
    public function __construct(private readonly AuthorizationCheckerInterface $authorization)
    {
    }

    public function __invoke(): void
    {
        if (!$this->authorization->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, 'church_tools_events')) {
            throw new AccessDeniedException('ChurchTools module access required.');
        }
        if (!in_array(Input::get('act'), [null, '', 'show'], true)) {
            throw new AccessDeniedException('ChurchTools source entries are read-only.');
        }
    }
}
