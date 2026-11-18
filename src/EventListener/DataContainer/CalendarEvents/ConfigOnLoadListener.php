<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\EventListener\DataContainer\CalendarEvents;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\Message;
use Koertho\ContaoChurchTools\Calendar\Backend\BackendActionGuard;
use Koertho\ContaoChurchTools\Calendar\Backend\EventProtection;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCallback(table: 'tl_calendar_events', target: 'config.onload', priority: 1024)]
final class ConfigOnLoadListener
{
    public function __construct(private readonly EventProtection $protection, private readonly BackendActionGuard $guard, private readonly UrlGeneratorInterface $router, private readonly TranslatorInterface $translator, private readonly ?RequestStack $requests = null) {}

    public function __invoke(?DataContainer $dc = null): void
    {
        $id = (int) ($dc?->id ?? 0);
        $this->guard->guard('tl_calendar_events', $id);
        $row = $id ? $this->protection->record($id) : null;
        if (($row['ct_sync_key'] ?? null) === null) {
            return;
        }
        $GLOBALS['TL_CSS'][] = 'bundles/churchtools/backend.css|static';
        $request = $this->requests?->getCurrentRequest();
        if ($request?->isMethod('GET') && $request->query->get('act') === 'edit') {
            Message::addInfo($this->translator->trans('sourceFieldsLocked', [], 'church_tools_backend'));
        }
        $GLOBALS['TL_DCA']['tl_calendar_events']['fields']['author']['eval']['mandatory'] = false;
        foreach ([...EventProtection::SOURCE_FIELDS, 'ct_description'] as $field) {
            $GLOBALS['TL_DCA']['tl_calendar_events']['fields'][$field]['eval']['readonly'] = true;
            $GLOBALS['TL_DCA']['tl_calendar_events']['fields'][$field]['eval']['versionize'] = false;
            $classes = $GLOBALS['TL_DCA']['tl_calendar_events']['fields'][$field]['eval']['tl_class'] ?? '';
            $GLOBALS['TL_DCA']['tl_calendar_events']['fields'][$field]['eval']['tl_class'] = trim($classes.' ct-source-locked');
        }
        $GLOBALS['TL_DCA']['tl_calendar_events']['fields']['published']['eval']['versionize'] = false;
        foreach (['default', 'internal', 'article', 'external'] as $palette) {
            $GLOBALS['TL_DCA']['tl_calendar_events']['palettes'][$palette] = str_replace('location,address,teaser', 'location,address,ct_description,ct_image_src,teaser', $GLOBALS['TL_DCA']['tl_calendar_events']['palettes'][$palette]);
        }
        if ((int) ($row['ct_series_template_id'] ?? 0) > 0) {
            $url = $this->router->generate('contao_backend', ['do' => 'ct_series', 'table' => 'tl_content', 'id' => $row['ct_series_template_id']]);
            Message::addInfo('<a href="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars($this->translator->trans('editSeries', [], 'church_tools_backend'), ENT_QUOTES, 'UTF-8').'</a>');
        }
    }
}
