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

#[AsContentElement('church_tools_calendar', category: 'church_tools', template: 'content_element/church_tools_calendar')]
final class MonthCalendarController extends AbstractContentElementController
{
    public function __construct(private readonly DisplayResolver $resolver, private readonly AppointmentViews $views, private readonly RequestStack $requests) {}

    protected function getResponse(FragmentTemplate $template, ContentModel $model, Request $request): Response
    {
        $request->attributes->set('church_tools_dynamic_content', true);
        $pageRequest = $this->requests->getMainRequest() ?? $request;
        $pageRequest->attributes->set('church_tools_dynamic_content', true);
        $zone = new \DateTimeZone(date_default_timezone_get());
        $key = 'ct_month_'.(int) $model->id;
        $value = $pageRequest->query->all()[$key] ?? null;
        $month = is_string($value) && preg_match('/^[1-9][0-9]{3}-(?:0[1-9]|1[0-2])$/D', $value)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value.'-01', $zone)
            : false;
        $currentMonth = new \DateTimeImmutable('first day of this month 00:00:00', $zone);
        $month = $month ?: $currentMonth;
        $ids = StringUtil::deserialize($model->churchToolsArchives, true);
        $rows = $this->resolver->resolve(is_array($ids) ? $ids : [],
            new \DateTimeImmutable('1000-01-01 00:00:00', $zone), new \DateTimeImmutable('9999-12-31 23:59:59.999999', $zone));
        $template->set('calendar', $this->views->calendar($rows, $currentMonth, $month, $pageRequest->getLocale(), $pageRequest, $key));
        $template->set('emptyLabel', $this->views->label('empty', $pageRequest->getLocale()));
        $template->set('elementId', 'church-tools-calendar-'.(int) $model->id);
        $response = $template->getResponse();
        $response->headers->set('Cache-Control', 'private, no-store, no-cache, must-revalidate');
        return $response;
    }
}
