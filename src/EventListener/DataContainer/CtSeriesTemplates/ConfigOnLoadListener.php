<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\EventListener\DataContainer\CtSeriesTemplates;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[AsCallback(table: 'tl_ct_series_templates', target: 'config.onload')]
final class ConfigOnLoadListener
{
    public function __construct(private readonly AuthorizationCheckerInterface $authorization) {}

    public function __invoke(): void
    {
        if (!$this->authorization->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, 'ct_series')) {
            throw new AccessDeniedException('ChurchTools series module access required.');
        }
    }
}
