<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Tests\Calendar;

use Contao\Controller;
use Contao\CoreBundle\DataContainer\ClipboardManager;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\DC_Table;
use Contao\Versions;
use Koertho\ContaoChurchTools\EventListener\DataContainer\CalendarEvents\ConfigOnLoadListener;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Koertho\ContaoChurchTools\Calendar\Backend\BackendActionGuard;
use Koertho\ContaoChurchTools\Calendar\Backend\CalendarAssignment;
use Koertho\ContaoChurchTools\Calendar\Backend\EventProtection;
use Koertho\ContaoChurchTools\Calendar\Backend\PublicationChanges;
use Koertho\ContaoChurchTools\Calendar\Security\CalendarProtectionVoter;
use Koertho\ContaoChurchTools\Calendar\Security\ImportedEventVoter;
use Koertho\ContaoChurchTools\Calendar\Security\SeriesContentVoter;
use Koertho\ContaoChurchTools\Calendar\Security\SeriesTemplateVoter;
use Koertho\ContaoChurchTools\EventListener\DataContainer\Calendar\FieldsCtCalendarIdSaveListener;
use Koertho\ContaoChurchTools\EventListener\DataContainer\Calendar\FieldsCtSyncStatusInputFieldListener;
use Koertho\ContaoChurchTools\EventListener\DataContainer\CalendarEvents\FieldsProtectedSaveListener;
use Koertho\ContaoChurchTools\EventListener\DataContainer\CalendarEvents\FieldsPublishedSaveListener;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Real schema/DB and action objects; all fixtures live in one rolled-back transaction. */
final class BackendAcceptance
{
    public static function run(ContainerInterface $container): int
    {
        $checks = 0;
        $check = static function (bool $condition, string $message) use (&$checks): void {
            if (!$condition) throw new \RuntimeException($message);
            ++$checks;
        };
        $db = $container->get('database_connection');
        foreach (['tl_calendar', 'tl_calendar_events', 'tl_ct_series_templates', 'tl_content'] as $table) Controller::loadDataContainer($table);
        $db->beginTransaction();
        try {
            $db->insert('tl_calendar', ['title' => 'V2 backend fixture', 'ct_calendar_id' => 4100000110]);
            $calendar = (int) $db->lastInsertId();
            $db->insert('tl_calendar', ['title' => 'V2 manual calendar']);
            $manual = (int) $db->lastInsertId();
            $db->insert('tl_calendar', ['title' => 'V2 another manual calendar']);
            $check(true, 'Multiple null assignments must be permitted.');
            try {
                $db->update('tl_calendar', ['ct_calendar_id' => 4100000110], ['id' => $manual]);
                throw new \RuntimeException('Unique assignment not enforced.');
            } catch (UniqueConstraintViolationException) { ++$checks; }
            $assignment = new CalendarAssignment($db);
            $check($assignment->save('4100000110', $calendar) === 4100000110, 'Own saved assignment rejected.');
            $check($assignment->save('', $calendar) === null, 'Deselection must store null.');
            try { $assignment->save('4100000110', $manual); throw new \RuntimeException('Duplicate assignment accepted.'); }
            catch (\InvalidArgumentException) { ++$checks; }

            $db->insert('tl_ct_series_templates', ['tstamp' => time(), 'ct_appointment_id' => 4100000001, 'title' => 'Source series']);
            $series = (int) $db->lastInsertId();
            $db->insert('tl_calendar_events', ['pid' => $calendar, 'title' => 'Source title', 'ct_sync_key' => hash('sha256', 'backend-fixture'), 'ct_series_template_id' => $series, 'startDate' => 1792886400, 'endDate' => 1792886400, 'startTime' => 1792888200, 'endTime' => 1792891800, 'ct_unpublished_by_sync' => '1', 'published' => 0, 'teaser' => 'Original teaser']);
            $event = (int) $db->lastInsertId();
            $row = $db->fetchAssociative('SELECT * FROM tl_calendar_events WHERE id=?', [$event]);
            $token = new NullToken();
            $eventVoter = new ImportedEventVoter();
            $attribute = [ContaoCorePermissions::DC_PREFIX.'tl_calendar_events'];
            $check($eventVoter->vote($token, new DeleteAction('tl_calendar_events', $row), $attribute) === VoterInterface::ACCESS_DENIED, 'Direct imported delete allowed.');
            $check($eventVoter->vote($token, new UpdateAction('tl_calendar_events', $row, ['pid' => $manual]), $attribute) === VoterInterface::ACCESS_DENIED, 'Direct imported move allowed.');
            $check($eventVoter->vote($token, new UpdateAction('tl_calendar_events', $row, ['title' => 'Forged']), $attribute) === VoterInterface::ACCESS_DENIED, 'Direct source update allowed.');
            $check($eventVoter->vote($token, new UpdateAction('tl_calendar_events', $row, ['teaser' => 'Editorial']), $attribute) === VoterInterface::ACCESS_ABSTAIN, 'Editorial update blocked.');
            $check((new CalendarProtectionVoter($db))->vote($token, new DeleteAction('tl_calendar', ['id' => $calendar]), [ContaoCorePermissions::DC_PREFIX.'tl_calendar']) === VoterInterface::ACCESS_DENIED, 'Archive cascade can delete imported content.');

            $dc = (new \ReflectionClass(DC_Table::class))->newInstanceWithoutConstructor();
            $dc->id = $event;
            $dc->field = 'title';
            $protection = new EventProtection($db);
            $check((new FieldsProtectedSaveListener($protection))('Forged', $dc) === 'Source title', 'Save callback changed source title.');
            $db->update('tl_calendar_events', ['startTime' => 1792891800, 'teaser' => 'Editorial survives'], ['id' => $event]);
            $protection->restore($event);
            $check((int) $db->fetchOne('SELECT startTime FROM tl_calendar_events WHERE id=?', [$event]) === 1792888200, 'Native date normalization changed instant.');
            $check($db->fetchOne('SELECT teaser FROM tl_calendar_events WHERE id=?', [$event]) === 'Editorial survives', 'Editorial update lost during date restoration.');
            $publication = new PublicationChanges($db);
            (new FieldsPublishedSaveListener($publication))('1', $dc);
            $check($db->fetchOne('SELECT ct_unpublished_by_sync FROM tl_calendar_events WHERE id=?', [$event]) === '1', 'Pre-validation publication callback changed sync marker.');
            $db->update('tl_calendar_events', ['published' => 1], ['id' => $event]);
            $publication->commit($event);
            $check($db->fetchOne('SELECT ct_unpublished_by_sync FROM tl_calendar_events WHERE id=?', [$event]) === '', 'Manual publication failed to clear sync flag.');

            $stack = new RequestStack();
            $clipboard = new ClipboardManager($container->get('contao.framework'), $stack);
            foreach (['copy', 'cut', 'delete'] as $action) {
                $stack->push(new Request(['act' => $action]));
                try { (new BackendActionGuard($db, $stack, $clipboard))->guard('tl_calendar_events', $event); throw new \RuntimeException('Direct '.$action.' allowed.'); }
                catch (AccessDeniedException) { ++$checks; }
                finally { $stack->pop(); }
            }
            $bulkRequest = new Request(['act' => 'copyAll'], ['IDS' => [$event + 999]]);
            $bulkRequest->setSession(new Session(new MockArraySessionStorage()));
            $stack->push($bulkRequest);
            $clipboard->setIds('tl_calendar_events', [$event], 'copyAll', false);
            try { (new BackendActionGuard($db, $stack, $clipboard))->guard('tl_calendar_events', 0); throw new \RuntimeException('Bulk copy allowed.'); }
            catch (AccessDeniedException) { ++$checks; }
            finally { $stack->pop(); }

            $calendarDc = (new \ReflectionClass(DC_Table::class))->newInstanceWithoutConstructor();
            $calendarDc->id = $calendar;
            $stack->push(new Request());
            $check((new FieldsCtCalendarIdSaveListener($assignment, $db, $stack))(null, $calendarDc) === 4100000110, 'Missing form field cleared source selection.');
            $stack->pop();
            $stack->push(new Request([], ['ct_calendar_id' => '']));
            $check((new FieldsCtCalendarIdSaveListener($assignment, $db, $stack))('', $calendarDc) === null, 'Explicit deselection not respected.');
            $stack->pop();

            // Execute native version restoration, which bypasses save callbacks.
            $db->update('tl_calendar_events', ['ct_series_template_id' => null], ['id' => $event]);
            $versionProtection = new EventProtection($db);
            (new ConfigOnLoadListener($versionProtection, new BackendActionGuard($db, $stack, $clipboard), $container->get('router'), $container->get('translator')))($dc);
            $db->update('tl_calendar_events', ['ct_series_template_id' => $series], ['id' => $event]);
            $check(($GLOBALS['TL_DCA']['tl_calendar_events']['fields']['author']['eval']['mandatory'] ?? true) === false, 'Imported enrichment unexpectedly requires a local author.');
            $historical = array_replace($row, ['title' => 'Stale version title', 'startTime' => 123, 'ct_sync_key' => 'stale-version-key', 'ct_series_template_id' => null, 'ct_hash' => 'stale-hash', 'teaser' => 'Restored editorial teaser']);
            $db->insert('tl_version', ['pid' => $event, 'tstamp' => time(), 'version' => 1, 'fromTable' => 'tl_calendar_events', 'data' => serialize($historical)]);
            (new Versions('tl_calendar_events', $event))->restore(1);
            $restored = $db->fetchAssociative('SELECT * FROM tl_calendar_events WHERE id=?', [$event]);
            $check($restored['title'] === 'Source title' && (int) $restored['startTime'] === 1792888200, 'Version restoration changed source fields.');
            $check($restored['ct_sync_key'] === $row['ct_sync_key'] && (int) $restored['ct_series_template_id'] === $series, 'Version restoration changed sync references.');
            $check($restored['teaser'] === 'Restored editorial teaser', 'Version restoration lost editorial data.');
            $historicalSeries = ['id' => $series, 'ct_appointment_id' => 8, 'title' => 'Old series title', 'teaser' => 'Restored series teaser'];
            $db->insert('tl_version', ['pid' => $series, 'tstamp' => time(), 'version' => 1, 'fromTable' => 'tl_ct_series_templates', 'data' => serialize($historicalSeries)]);
            (new Versions('tl_ct_series_templates', $series))->restore(1);
            $restoredSeries = $db->fetchAssociative('SELECT * FROM tl_ct_series_templates WHERE id=?', [$series]);
            $check($restoredSeries['title'] === 'Source series' && (int) $restoredSeries['ct_appointment_id'] === 4100000001, 'Version restoration changed series source identity.');
            $check($restoredSeries['teaser'] === 'Restored series teaser', 'Version restoration lost series editorial data.');
            $check(($GLOBALS['TL_DCA']['tl_calendar_events']['list']['operations']['copy']['method'] ?? null) === 'POST' && str_contains($GLOBALS['TL_DCA']['tl_calendar_events']['list']['operations']['copy']['href'], 'mode=copy'), 'Manual copy lost native clipboard operation.');
            $check(($GLOBALS['TL_DCA']['tl_calendar_events']['list']['operations']['delete']['method'] ?? null) === 'DELETE', 'Manual delete lost native method.');

            $denied = new class implements AuthorizationCheckerInterface { public function isGranted(mixed $attribute, mixed $subject = null, mixed ...$options): bool { return false; } };
            $allowed = new class implements AuthorizationCheckerInterface { public function isGranted(mixed $attribute, mixed $subject = null, mixed ...$options): bool { return true; } };
            $seriesRow = $db->fetchAssociative('SELECT * FROM tl_ct_series_templates WHERE id=?', [$series]);
            $seriesAttribute = [ContaoCorePermissions::DC_PREFIX.'tl_ct_series_templates'];
            $check((new SeriesTemplateVoter($db, $denied))->vote($token, new ReadAction('tl_ct_series_templates', $seriesRow), $seriesAttribute) === VoterInterface::ACCESS_DENIED, 'Series module permission bypassed.');
            $check((new SeriesTemplateVoter($db, $allowed))->vote($token, new DeleteAction('tl_ct_series_templates', $seriesRow), $seriesAttribute) === VoterInterface::ACCESS_DENIED, 'Referenced series deletion allowed.');
            $db->insert('tl_content', ['pid' => $series, 'ptable' => 'tl_ct_series_templates', 'type' => 'text']);
            $parent = (int) $db->lastInsertId();
            $child = ['id' => 0, 'pid' => $parent, 'ptable' => 'tl_content'];
            $contentAttribute = [ContaoCorePermissions::DC_PREFIX.'tl_content'];
            $check((new SeriesContentVoter($db, $denied))->vote($token, new ReadAction('tl_content', $child), $contentAttribute) === VoterInterface::ACCESS_DENIED, 'Nested series permission bypassed.');
            $check((new SeriesContentVoter($db, $allowed))->vote($token, new ReadAction('tl_content', $child), $contentAttribute) === VoterInterface::ACCESS_ABSTAIN, 'Authorized nested series content denied.');
            $db->update('tl_calendar', ['ct_sync_error' => '<script>error</script>', 'ct_sync_counts' => '{"inserted":3,"unknown":"<script>"}'], ['id' => $calendar]);
            $html = (new FieldsCtSyncStatusInputFieldListener($db, $container->get('translator')))($calendarDc);
            $check(!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;') && str_contains($html, ': 3'), 'Status output is unsafe or lost counts.');
            foreach (EventProtection::SYNC_FIELDS as $field) {
                $check(($GLOBALS['TL_DCA']['tl_calendar_events']['fields'][$field]['eval']['doNotCopy'] ?? false) === true, 'Copied reference: '.$field);
            }
        } finally {
            $db->rollBack();
        }

        return $checks;
    }
}
