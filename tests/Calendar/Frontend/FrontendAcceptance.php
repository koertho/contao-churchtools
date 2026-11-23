<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Tests\Calendar\Frontend;

use Contao\CalendarEventsModel;
use Contao\ContentModel;
use Contao\FrontendTemplate;
use Contao\Model\Registry;
use Contao\ModuleEventlist;
use Contao\ModuleEventReader;
use Contao\Input;
use Koertho\ContaoChurchTools\Calendar\Sync\CalendarEventMapper;
use Contao\ModuleModel;
use Contao\PageModel;
use Contao\Template;
use Koertho\ContaoChurchTools\Calendar\Frontend\EventContentResolver;
use Koertho\ContaoChurchTools\EventListener\DataContainer\Content\ConfigOnInvalidateCacheTagsListener;
use Koertho\ContaoChurchTools\EventListener\Frontend\GetAllEventsListener;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

final class FrontendAcceptance
{
    public static function run(ContainerInterface $container): int
    {
        $db = $container->get('database_connection');
        $checks = 0;
        $check = static function (bool $pass, string $message) use (&$checks): void {
            ++$checks;
            if (!$pass) throw new \RuntimeException('Frontend: '.$message);
        };
        $prefix = 'ct-v2-fe-'.bin2hex(random_bytes(5));
        $ids = ['tl_page'=>[], 'tl_calendar'=>[], 'tl_ct_series_templates'=>[], 'tl_calendar_events'=>[], 'tl_content'=>[], 'tl_files'=>[]];
        $imagePath = null;
        $requests = $container->get('request_stack');
        $request = Request::create('https://example.org/calendar-v2-test');
        $request->attributes->set('_scope', 'frontend');
        $request->attributes->set('_locale', 'en');
        $requests->push($request);
        $previousPage = $GLOBALS['objPage'] ?? null;
        $previousLanguage = $GLOBALS['TL_LANGUAGE'] ?? null;
        try {
            $insert = static function (string $table, array $row) use ($db, &$ids): int {
                $db->insert($table, ['tstamp'=>time()] + $row);
                $id = (int) $db->lastInsertId();
                $ids[$table][] = $id;
                return $id;
            };
            $root = $insert('tl_page', ['title'=>$prefix.' root', 'type'=>'root', 'alias'=>$prefix.'-root', 'language'=>'en', 'fallback'=>'1', 'published'=>'1', 'dateFormat'=>'Y-m-d', 'timeFormat'=>'H:i', 'datimFormat'=>'Y-m-d H:i', 'urlSuffix'=>'.html']);
            $page = $insert('tl_page', ['pid'=>$root, 'title'=>$prefix.' reader', 'type'=>'regular', 'alias'=>$prefix.'-reader', 'published'=>'1']);
            $GLOBALS['objPage'] = PageModel::findByPk($page)->loadDetails();
            $GLOBALS['TL_LANGUAGE'] = 'en';
            $request->attributes->set('pageModel', $GLOBALS['objPage']);
            $container->get('contao.routing.response_context_factory')->createContaoWebpageResponseContext($GLOBALS['objPage']);
            $archive = $insert('tl_calendar', ['title'=>$prefix, 'jumpTo'=>$page]);
            $series = $insert('tl_ct_series_templates', ['ct_appointment_id'=>random_int(1000000, 2000000), 'title'=>$prefix, 'teaser'=>'<p>Shared teaser</p>']);
            $start = (new \DateTimeImmutable('tomorrow'))->setTime(10, 0)->getTimestamp();
            $event = $insert('tl_calendar_events', ['pid'=>$archive, 'title'=>'Imported fixture', 'alias'=>$prefix, 'published'=>'1', 'startDate'=>strtotime('tomorrow'), 'endDate'=>strtotime('tomorrow'), 'startTime'=>$start, 'endTime'=>$start+3600, 'addTime'=>'1', 'source'=>'default', 'ct_sync_key'=>hash('sha256',$prefix), 'ct_series_template_id'=>$series, 'ct_description'=>'<b>Literal</b> {{env::host}}']);
            $insert('tl_content', ['pid'=>$event, 'ptable'=>'tl_calendar_events', 'type'=>'text', 'sorting'=>128, 'text'=>'<p>Individual detail marker</p>']);
            $insert('tl_content', ['pid'=>$series, 'ptable'=>'tl_ct_series_templates', 'type'=>'text', 'sorting'=>128, 'text'=>'<p>Series detail marker</p>']);
            $insert('tl_content', ['pid'=>$series, 'ptable'=>'tl_ct_series_templates', 'type'=>'text', 'sorting'=>256, 'text'=>'<p>Invisible detail marker</p>', 'invisible'=>'1']);
            $insert('tl_content', ['pid'=>$series, 'ptable'=>'tl_ct_series_templates', 'type'=>'text', 'sorting'=>384, 'text'=>'<p>Protected detail marker</p>', 'protected'=>'1', 'groups'=>serialize([2147483000])]);
            $insert('tl_content', ['pid'=>$series, 'ptable'=>'tl_ct_series_templates', 'type'=>'text', 'sorting'=>512, 'text'=>'<p>Future detail marker</p>', 'start'=>(string)(time()+86400)]);
            $resolver = new EventContentResolver($db, $container->get('contao.framework'), $container->get('contao.cache.tag_manager'), $container->getParameter('kernel.project_dir'), $requests);
            $row = $db->fetchAssociative('SELECT * FROM tl_calendar_events WHERE id=?', [$event]);
            $resolved = $resolver->resolve($row + ['href'=>'/reader']);
            $check($resolved['teaser']==='<p>Shared teaser</p>', 'native row uses series teaser');
            $check(($resolved['hasDetails'])(), 'series content provides details link');
            $html = ($resolved['details'])();
            $check(str_contains($html, 'Individual detail marker') && str_contains($html, 'Series detail marker'), 'real text content renders for both parents: '.substr($html, 0, 1500));
            $check(strpos($html, 'Individual detail marker') < strpos($html, 'Series detail marker'), 'individual contents precede series contents');
            $check(!str_contains($html, 'Invisible detail marker') && !str_contains($html, 'Protected detail marker') && !str_contains($html, 'Future detail marker'), 'native content visibility and protection respected');
            $check(($resolved['details'])()===$html, 'details closure stable on repeat invocation');
            $hooked = (new GetAllEventsListener($resolver))([date('Ymd',$start)=>[$start=>[$row + ['href'=>'/reader']]]]);
            $check(($hooked[date('Ymd',$start)][$start][0]['details'])()===$html, 'hook uses identical combined contents');
            $context = $row + ['class'=>'', 'datetime'=>date('Y-m-d',$start), 'date'=>date('Y-m-d',$start), 'time'=>'10:00', 'recurring'=>'', 'location'=>'', 'address'=>'', 'enclosure'=>[], 'getSchemaOrgData'=>Template::once(static fn()=>['@type'=>'Event', 'name'=>'Imported fixture'])];
            $template = new FrontendTemplate('event_full_churchtools');
            $template->setData($context);
            $reader = $template->parse();
            $check(str_contains($reader, 'Individual detail marker') && str_contains($reader, 'Series detail marker'), 'actual managed reader Twig renders combined details');
            $check(strpos($reader, 'Individual detail marker') < strpos($reader, 'Series detail marker'), 'reader preserves ordering');
            $check(!str_contains($reader, 'Protected detail marker'), 'reader does not leak protected contents');
            // Native generator invokes registered hook when a native module requests events.
            $module = new ModuleEventlist(new ModuleModel(['id'=>0, 'type'=>'eventlist', 'cal_calendar'=>serialize([$archive])]));
            $events = $container->get('contao_calendar.generator.calendar_events')->getAllEvents([$archive], new \DateTimeImmutable('today'), new \DateTimeImmutable('+2 days'), null, false, null, $module);
            $native = [];
            foreach ($events as $day) foreach ($day as $time) foreach ($time as $item) if ((int)$item['id']===$event) $native[]=$item;
            $check(count($native)===1, 'native generator emits imported single instance');
            $check($native[0]['teaser']==='<p>Shared teaser</p>', 'registered hook enriches native event generator');
            $check(str_contains(($native[0]['details'])(), 'Series detail marker'), 'native event generator retains series detail closure');
            // Exercise nested invalidation with real stored parent records.
            $parent = $insert('tl_content', ['pid'=>$series, 'ptable'=>'tl_ct_series_templates', 'type'=>'text', 'sorting'=>1024]);
            $child = $insert('tl_content', ['pid'=>$parent, 'ptable'=>'tl_content', 'type'=>'text', 'sorting'=>128]);
            $dc = new class extends \Contao\DataContainer {
                public function __construct() {}
                public function getPalette() { return ''; }
                public function save($value) {}
            };
            $dc->id = $child;
            $tags = (new ConfigOnInvalidateCacheTagsListener($db))($dc, ['contao.db.tl_content.'.$child]);
            $check(in_array('contao.db.tl_ct_series_templates.'.$series, $tags, true), 'nested native content reaches series cache tag');
            $db->delete('tl_content', ['id'=>$child]);
            $tags = (new ConfigOnInvalidateCacheTagsListener($db))($dc, ['contao.db.tl_content.'.$parent]);
            $check(in_array('contao.db.tl_ct_series_templates.'.$series, $tags, true), 'deleted nested content still reaches series through parent tag');
            // CT description remains a safe fallback when neither editor supplied a teaser.
            $db->update('tl_ct_series_templates', ['teaser'=>''], ['id'=>$series]);
            $resolver->reset();
            $fallback = $resolver->resolve($row + ['href'=>'/reader']);
            $check(str_contains($fallback['teaser'], '&lt;b&gt;Literal&lt;/b&gt;') && str_contains($fallback['teaser'], '&#123;&#123;env::host&#125;&#125;'), 'source description HTML and insert tags stay literal');
            // A real managed image verifies complete figure/metadata fallback in the reader.
            $projectDir = $container->getParameter('kernel.project_dir');
            if (!is_dir($projectDir.'/files')) mkdir($projectDir.'/files', 0700, true);
            $relativeImage = 'files/'.$prefix.'.png';
            $imagePath = $projectDir.'/'.$relativeImage;
            $image = imagecreatetruecolor(4, 4);
            imagepng($image, $imagePath);
            $uuid = \Symfony\Component\Uid\Uuid::v1()->toBinary();
            $insert('tl_files', ['type'=>'file', 'uuid'=>$uuid, 'path'=>$relativeImage, 'name'=>$prefix.'.png', 'extension'=>'png']);
            $db->update('tl_ct_series_templates', ['addImage'=>'1', 'singleSRC'=>$uuid, 'size'=>serialize([4,4,'crop']), 'floating'=>'above', 'overwriteMeta'=>'1', 'alt'=>'Series image alt', 'caption'=>'Series caption marker', 'fullsize'=>'0'], ['id'=>$series]);
            $db->update('tl_calendar_events', ['addImage'=>'1', 'singleSRC'=>str_repeat('x',16)], ['id'=>$event]);
            foreach ($ids['tl_content'] as $contentId) $db->delete('tl_content', ['id'=>$contentId]);
            Registry::getInstance()->reset();
            $requests->pop();
            $nextRequest = Request::create('https://example.org/calendar-v2-test-image');
            $nextRequest->attributes->set('_scope', 'frontend');
            $nextRequest->attributes->set('_locale', 'en');
            $nextRequest->attributes->set('pageModel', $GLOBALS['objPage']);
            $requests->push($nextRequest);
            $container->get('contao.routing.response_context_factory')->createContaoWebpageResponseContext($GLOBALS['objPage']);
            $template = new FrontendTemplate('event_full_churchtools');
            $template->setData($context);
            $imageReader = $template->parse();
            $check(str_contains($imageReader, '<img') && str_contains($imageReader, 'Series image alt'), 'reader uses actual valid series image when own file is missing');
            $check(str_contains($imageReader, 'Series caption marker'), 'native figure preserves inherited metadata');
            $sizedImage = $container->get('twig')->createTemplate("{% with churchtools_event(_context, [2,2,'crop']) %}{{ include('@Contao/image.html.twig') }}{% endwith %}")->render($context);
            $sizedDocument = new \DOMDocument();
            @$sizedDocument->loadHTML($sizedImage);
            $check((new \DOMXPath($sizedDocument))->query('//img[@width="2"]')->length === 1, 'explicit Twig image size overrides inherited reader default');

            $check(str_contains($imageReader, '&lt;b&gt;Literal&lt;/b&gt;') && str_contains($imageReader, '&#123;&#123;env::host&#125;&#125;'), 'actual reader keeps source description literal');
            $literalTitle = version_compare(\Contao\CoreBundle\ContaoCoreBundle::getVersion(), '6.0', '<') ? 'Source & <b>title</b> {{env::host}}' : 'Source & <b>title</b> {literal}';
            $mapped = (new CalendarEventMapper())->map(['title'=>$literalTitle, 'location'=>version_compare(\Contao\CoreBundle\ContaoCoreBundle::getVersion(), '6.0', '<') ? 'Room & <b>Hall</b> {{user::email}}' : 'Room & <b>Hall</b> {literal}', 'address'=>'Street & <b>Address</b>', 'sourceStart'=>date('c',$start), 'sourceEnd'=>date('c',$start+3600), 'allDay'=>false, 'occurrenceUid'=>$prefix, 'sourceCalendarId'=>110, 'sourceAppointmentId'=>1, 'description'=>'Description'], new \DateTimeZone(date_default_timezone_get()));
            if (version_compare(\Contao\CoreBundle\ContaoCoreBundle::getVersion(), '6.0', '<')) {
                $headTitle = $container->get('contao.string.html_decoder')->inputEncodedToPlainText($mapped['title']);
                $check(!str_contains($headTitle, '{{'), 'native reader page-title conversion escapes CT insert tag braces');
            }
            $db->update('tl_calendar_events', array_intersect_key($mapped,array_flip(['title','location','address'])), ['id'=>$event]);
            Registry::getInstance()->reset();
            $listModule = new ModuleEventlist(new ModuleModel(['id'=>0, 'type'=>'eventlist', 'cal_calendar'=>serialize([$archive]), 'cal_template'=>'event_list_churchtools', 'cal_format'=>'next_7', 'cal_noSpan'=>'', 'cal_limit'=>0, 'perPage'=>0, 'cal_order'=>'ascending', 'imgSize'=>serialize([2,2,'crop'])]));
            $listHtml = $container->get('contao.insert_tag.parser')->replace($listModule->generate());
            $document = new \DOMDocument();
            @$document->loadHTML($listHtml);
            $xpath = new \DOMXPath($document);
            $check($xpath->query('//img[@alt="Series image alt" and @width="2"]')->length === 1, 'actual native list inherits series image metadata and preserves module image size');
            $check(str_contains($listHtml, 'Series caption marker'), 'actual native list inherits series caption');
            $check($xpath->query('//figure//a[img]')->item(0)?->getAttribute('href') === $xpath->query('//h2/a')->item(0)?->getAttribute('href'), 'inherited list image links to event reader');
            $check($xpath->query('//h2/a')->item(0)?->textContent === $literalTitle, 'actual list title remains literal without double encoding');
            $check(str_contains($xpath->query('//p[contains(@class,"location")]')->item(0)?->textContent ?? '', version_compare(\Contao\CoreBundle\ContaoCoreBundle::getVersion(), '6.0', '<') ? 'Room & <b>Hall</b> {{user::email}}' : 'Room & <b>Hall</b> {literal}'), 'actual list location remains literal without double encoding');
            // ChurchTools image is used when both editorial image sources are missing.
            $db->update('tl_ct_series_templates', ['addImage'=>0], ['id'=>$series]);
            $db->update('tl_calendar_events', ['ct_image_src'=>$uuid, 'overwriteMeta'=>'1', 'alt'=>'Stale event alt', 'caption'=>'Stale event caption'], ['id'=>$event]);
            Registry::getInstance()->reset();
            $requests->pop();
            $ctRequest = Request::create('https://example.org/calendar-v2-test-ct-image');
            $ctRequest->attributes->set('_scope', 'frontend');
            $ctRequest->attributes->set('_locale', 'en');
            $ctRequest->attributes->set('pageModel', $GLOBALS['objPage']);
            $requests->push($ctRequest);
            $container->get('contao.routing.response_context_factory')->createContaoWebpageResponseContext($GLOBALS['objPage']);
            $listHtml = $container->get('contao.insert_tag.parser')->replace($listModule->generate());
            @$document->loadHTML($listHtml);
            $xpath = new \DOMXPath($document);
            $check($xpath->query('//img[@width="2"]')->length === 1, 'native list displays ChurchTools image with module dimensions');
            $check(!str_contains($listHtml, 'Stale event alt') && !str_contains($listHtml, 'Series image alt'), 'ChurchTools list image does not inherit editorial metadata');
            Input::setGet('auto_item', $prefix);
            $ctReaderModule = new ModuleEventReader(new ModuleModel(['id'=>0, 'type'=>'eventreader', 'cal_calendar'=>serialize([$archive]), 'cal_template'=>'event_full_churchtools', 'imgSize'=>serialize([2,2,'crop'])]));
            $ctReaderHtml = $container->get('contao.insert_tag.parser')->replace($ctReaderModule->generate());
            @$document->loadHTML($ctReaderHtml);
            $xpath = new \DOMXPath($document);
            $check($xpath->query('//img')->length === 1, 'native reader displays ChurchTools image');
            $check(!str_contains($ctReaderHtml, 'Stale event alt') && !str_contains($ctReaderHtml, 'Stale event caption'), 'ChurchTools reader image does not inherit editorial metadata');
            Input::setGet('auto_item', null);
            // A valid individual image keeps native reader module image-size overrides.
            $db->update('tl_calendar_events', ['singleSRC'=>$uuid], ['id'=>$event]);
            Registry::getInstance()->reset();
            $nativeEvent = CalendarEventsModel::findPublishedByParentAndIdOrAlias($prefix, [$archive]);
            $check((string)$nativeEvent->addImage === '1' && $nativeEvent->singleSRC === $uuid, 'native event model sees valid individual image: '.bin2hex((string)$nativeEvent->singleSRC).' expected '.bin2hex($uuid).' addImage '.serialize($nativeEvent->addImage));
            Input::setGet('auto_item', $prefix);
            $readerModule = new ModuleEventReader(new ModuleModel(['id'=>0, 'type'=>'eventreader', 'cal_calendar'=>serialize([$archive]), 'cal_template'=>'event_full_churchtools', 'imgSize'=>serialize([2,2,'crop'])]));
            $moduleReaderHtml = $container->get('contao.insert_tag.parser')->replace($readerModule->generate());
            @$document->loadHTML($moduleReaderHtml);
            $xpath = new \DOMXPath($document);
            $check($xpath->query('//img[@width="2"]')->length === 1, 'native reader individual image preserves configured module dimensions');
            $check($xpath->query('//h1')->item(0)?->textContent === $literalTitle, 'actual reader title remains literal without double encoding');
            Input::setGet('auto_item', null);
            $manualId = $insert('tl_calendar_events', ['pid'=>$archive, 'title'=>'Manual fixture', 'published'=>'1']);
            $manualContext = array_replace($context, ['id'=>$manualId, 'title'=>'Manual fixture', 'details'=>Template::once(static fn()=>'<p>Manual detail marker</p>'), 'hasDetails'=>Template::once(static fn()=>true), 'addImage'=>false]);
            $template = new FrontendTemplate('event_full_churchtools');
            $template->setData($manualContext);
            $manualReader = $template->parse();
            $check(str_contains($manualReader, 'Manual detail marker') && !str_contains($manualReader, 'Series detail marker'), 'manual events keep their native template details');

        } finally {
            foreach (array_reverse($ids, true) as $table=>$tableIds) foreach (array_reverse($tableIds) as $id) $db->delete($table, ['id'=>$id]);
            if ($imagePath !== null && is_file($imagePath)) unlink($imagePath);
            Registry::getInstance()->reset();
            $GLOBALS['objPage'] = $previousPage;
            $GLOBALS['TL_LANGUAGE'] = $previousLanguage;
            $requests->pop();
        }

        return $checks;
    }
}
