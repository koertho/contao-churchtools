<?php

declare(strict_types=1);
namespace Koertho\ChurchToolsBundle\Tests\EventIntegration;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Koertho\ChurchToolsBundle\EventIntegration\DisplayResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\SecurityBundle\Security;

/** Test-only service/route registered only in the owned isolated harness configuration. */
final class ResolverController
{
    public function __construct(private readonly \Contao\CoreBundle\Framework\ContaoFramework $framework, private readonly \Contao\CoreBundle\Routing\ResponseContext\CoreResponseContextFactory $contexts, private readonly DisplayResolver $resolver, private readonly Security $security, private readonly ContaoCsrfTokenManager $csrf, #[Autowire('%contao.csrf_token_name%')] private readonly string $tokenName) {}
    public function __invoke(Request $request): Response
    {
        if ($request->query->has('login')) {
            return new Response('<form method="post"><input name="REQUEST_TOKEN" value="'.htmlspecialchars($this->csrf->getToken($this->tokenName)->getValue(), ENT_QUOTES).'"></form>');
        }
        if ($request->query->has('render')) {
            $this->framework->initialize();
            $page = \Contao\PageModel::findWithDetails($request->query->getInt('page'));
            $GLOBALS['objPage'] = $page;
            $this->contexts->createContaoWebpageResponseContext($page);
            $request->attributes->set('pageModel', $page);
            \Contao\Input::setGet('auto_item', $request->query->getString('render'));
            $event = \Contao\CalendarEventsModel::findByPk($request->query->getInt('render'));
            if ($request->query->has('list')) {
                \Contao\Input::setGet('year', '2026');
                $module = new \Contao\ModuleModel();
                $module->setRow(['id'=>0,'type'=>'eventlist','cal_calendar'=>serialize([(int)$event->pid]),'cal_template'=>'event_list','cal_format'=>'cal_year','cal_noSpan'=>1]);
                return new Response((new \Contao\ModuleEventlist($module))->generate());
            }
            $module = new \Contao\ModuleModel();
            $module->setRow(['id'=>0,'type'=>'eventreader','cal_calendar'=>serialize([(int)$event->pid]),'cal_template'=>'event_full']);
            if (!\Contao\ContentElement::findClass('text')) throw new \RuntimeException('Core content element registration missing.');
            $html = (new \Contao\ModuleEventReader($module))->generate();
            // Reader details and teaser deliberately differ; fallback cannot pass the assertion.
            return new Response($html);
        }
        if ($request->query->has('content')) {
            $this->framework->initialize();
            $page = \Contao\PageModel::findWithDetails($request->query->getInt('page'));
            $GLOBALS['objPage'] = $page;
            $this->contexts->createContaoWebpageResponseContext($page);
            $request->attributes->set('pageModel', $page);
            return new Response(\Contao\Controller::getContentElement($request->query->getInt('content')));
        }
        $rows = $this->resolver->resolve(explode(',', $request->query->getString('archives')), new \DateTimeImmutable($request->query->getString('from', '2026-01-01')), new \DateTimeImmutable($request->query->getString('until', '2027-01-01')));
        foreach ($rows as &$row) { $row['start']=$row['start']->format('Y-m-d H:i:s.uP'); $row['end']=$row['end']->format('Y-m-d H:i:s.uP'); }
        return new JsonResponse(['member'=>$this->security->isGranted('ROLE_MEMBER'), 'rows'=>$rows], headers: ['Cache-Control'=>'private, no-store']);
    }
}
