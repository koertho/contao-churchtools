<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Controller;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Koertho\ChurchToolsBundle\EventIntegration\BackendAccess;
use Koertho\ChurchToolsBundle\EventIntegration\LinkActions;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Controller\Backend\AbstractBackendController;

#[Route('%contao.backend.route_prefix%/church-tools/entry/{id}', name: 'church_tools_entry_actions', requirements: ['id' => '[1-9][0-9]*'], defaults: ['_scope' => 'backend'], methods: ['GET', 'POST'])]
final class EventActionsController extends AbstractBackendController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ContaoFramework $framework,
        private readonly BackendAccess $access,
        private readonly LinkActions $actions,
        private readonly UrlGeneratorInterface $router,
        private readonly ContaoCsrfTokenManager $csrf,
        #[Autowire('%contao.csrf_token_name%')] private readonly string $tokenName,
    ) {
    }

    public function __invoke(int $id, Request $request): Response
    {
        $this->framework->initialize();
        $entry = $this->connection->fetchAssociative('SELECT * FROM tl_church_tools_entry WHERE id=?', [$id]);
        if (!$entry) throw new NotFoundHttpException();
        $this->access->entry($entry);
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->csrf->isTokenValid(new CsrfToken($this->tokenName, $request->request->get('REQUEST_TOKEN')))) throw new AccessDeniedException('Invalid CSRF token.');
            $action = $request->request->getString('action');
            if (!in_array($action, ['link', 'unlink', 'create'], true)) throw new AccessDeniedException();
            try {
                $this->actions->change($id, $action, $request->request->getInt('target'));
                return new RedirectResponse($this->router->generate('church_tools_entry_actions', ['id' => $id]), 303);
            } catch (\DomainException $e) {
                $error = $e->getMessage();
            }
        }
        $target = $entry['contaoEventId'] === null ? false : $this->connection->fetchAssociative('SELECT * FROM tl_calendar_events WHERE id=?', [$entry['contaoEventId']]);
        $open = $target && $this->access->target($target) ? $this->router->generate('contao_backend', ['do' => 'calendar', 'table' => 'tl_calendar_events', 'act' => 'edit', 'id' => $target['id'], 'rt' => $this->csrf->getToken($this->tokenName)->getValue()]) : null;
        if ($request->query->get('open') === '1') {
            if (!$open) throw new AccessDeniedException();
            return new RedirectResponse($open);
        }
        $calendars = $events = [];
        if ($entry['contaoEventId'] === null) {
            foreach ($this->connection->fetchAllAssociative('SELECT * FROM tl_calendar ORDER BY title,id') as $calendar) {
                if ($this->access->calendar($calendar)) {
                    $calendars[] = ['id' => $calendar['id'], 'title' => (version_compare(\Contao\CoreBundle\ContaoCoreBundle::getVersion(), '6.0', '<') ? html_entity_decode($calendar['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8') : $calendar['title'])];
                }
            }
            foreach ($this->connection->fetchAllAssociative('SELECT * FROM tl_calendar_events ORDER BY title,id') as $event) {
                if ($this->access->target($event)) {
                    $events[] = ['id' => $event['id'], 'title' => (version_compare(\Contao\CoreBundle\ContaoCoreBundle::getVersion(), '6.0', '<') ? html_entity_decode($event['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8') : $event['title'])];
                }
            }
        }
        return $this->render('@Contao/backend/church_tools/actions.html.twig', [
            'entry' => $entry, 'calendars' => $calendars, 'events' => $events, 'open' => $open, 'actionError' => $error,
            'token' => $this->csrf->getToken($this->tokenName)->getValue(),
            'back' => $this->router->generate('contao_backend', ['do' => 'church_tools_events', 'table' => 'tl_church_tools_entry', 'id' => $entry['pid']]),
        ], new Response('', $error ? 422 : 200, ['Cache-Control' => 'private, no-store']));
    }
}
