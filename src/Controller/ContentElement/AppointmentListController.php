<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Controller\ContentElement;

use Contao\ContentModel;
use Contao\CoreBundle\Controller\ContentElement\AbstractContentElementController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsContentElement;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\StringUtil;
use Koertho\ChurchToolsBundle\EventIntegration\DisplayResolver;
use Koertho\ChurchToolsBundle\Frontend\AppointmentViews;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

#[AsContentElement('church_tools_list', category: 'church_tools', template: 'content_element/church_tools_list')]
final class AppointmentListController extends AbstractContentElementController
{
    public function __construct(private readonly DisplayResolver $resolver, private readonly AppointmentViews $views, private readonly RequestStack $requests) {}

    protected function getResponse(FragmentTemplate $template, ContentModel $model, Request $request): Response
    {
        $request->attributes->set('church_tools_dynamic_content', true);
        $pageRequest = $this->requests->getMainRequest() ?? $request;
        $pageRequest->attributes->set('church_tools_dynamic_content', true);
        $days = max(1, min(366, (int) ($model->churchToolsDays ?: 7)));
        $from = new \DateTimeImmutable('today', new \DateTimeZone(date_default_timezone_get()));
        $until = $from->modify('+'.$days.' days')->modify('-1 microsecond');
        $ids = StringUtil::deserialize($model->churchToolsArchives, true);
        $rows = $this->resolver->resolve(is_array($ids) ? $ids : [], $from, $until);
        $template->set('groups', $this->views->listGroups($rows, $from, $pageRequest->getLocale()));
        $template->set('emptyLabel', $this->views->label('empty', $pageRequest->getLocale()));
        $response = $template->getResponse();
        $response->headers->set('Cache-Control', 'private, no-store, no-cache, must-revalidate');
        return $response;
    }
}
