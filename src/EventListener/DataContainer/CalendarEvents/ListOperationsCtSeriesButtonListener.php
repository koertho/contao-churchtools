<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\EventListener\DataContainer\CalendarEvents;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCallback(table: 'tl_calendar_events', target: 'list.operations.ct_series.button')]
final class ListOperationsCtSeriesButtonListener
{
    public function __construct(private readonly UrlGeneratorInterface $router, private readonly TranslatorInterface $translator, private readonly AuthorizationCheckerInterface $authorization) {}

    public function __invoke(DataContainerOperation $operation): void
    {
        $id = (int) ($operation->getRecord()['ct_series_template_id'] ?? 0);
        if ($id < 1 || !$this->authorization->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, 'ct_series')) {
            $operation->hide();
            return;
        }
        $operation->setUrl($this->router->generate('contao_backend', ['do' => 'ct_series', 'table' => 'tl_content', 'id' => $id]));
        $operation['label'] = $operation['title'] = $this->translator->trans('editSeries', [], 'church_tools_backend');
    }
}
