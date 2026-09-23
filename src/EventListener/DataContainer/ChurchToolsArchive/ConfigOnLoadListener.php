<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\EventListener\DataContainer\ChurchToolsArchive;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[AsCallback(table: 'tl_church_tools_archive', target: 'config.onload')]
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
    }
}
