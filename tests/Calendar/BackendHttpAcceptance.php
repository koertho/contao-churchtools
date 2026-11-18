<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Tests\Calendar;

use Doctrine\DBAL\Connection;

/** Exercises only actual authenticated Contao backend routes and successful browser controls. */
final class BackendHttpAcceptance
{
    public static function run(Connection $db, \Closure $request, \Closure $check, int $calendar, int $event, int $series): void
    {
        $edit = '/contao?do=calendar&table=tl_calendar_events&act=edit&id='.$event;
        $base = '/contao?do=calendar&table=tl_calendar_events&id='.$event;
        $before = $db->fetchAssociative('SELECT * FROM tl_calendar_events WHERE id=?', [$event]);
        $sourceFields = ['title','pid','addTime','startDate','endDate','startTime','endTime','source','recurring','location','address','ct_sync_key','ct_appointment_id','ct_calendar_id','ct_series_template_id','ct_hash','ct_description'];
        $assertSource = static function (string $label) use ($db, $event, $before, $sourceFields, $check): void {
            $after = $db->fetchAssociative('SELECT * FROM tl_calendar_events WHERE id=?', [$event]);
            foreach ($sourceFields as $field) $check((string) $after[$field] === (string) $before[$field], $label.' preserved '.$field);
        };
        $form = $request($edit);
        $check($form[0] === 200, 'Authenticated imported event edit HTTP '.$form[0]);
        $check(str_contains($form[1], 'name="title"') && str_contains($form[1], 'readonly'), 'Imported source fields present as readonly');
        $check(str_contains($form[1], 'do=ct_series') && str_contains($form[1], 'id='.$series), 'Series navigation appears in actual event form');
        $post = self::controls($form[1], 'tl_calendar_events');
        $post['teaser'] = '<p>HTTP editorial teaser</p>';
        $post['published'] = '';
        $post['save'] = '1';
        // The technical importer has no backend author. Enrichment must not require one.
        $post['author'] = '';
        $saved = $request($edit, $post);
        $check($saved[0] < 400 && $db->fetchOne('SELECT teaser FROM tl_calendar_events WHERE id=?', [$event]) === '<p>HTTP editorial teaser</p>', 'Real teaser POST saved; HTTP '.$saved[0].self::errors($saved[1]));
        $check((int) $db->fetchOne('SELECT author FROM tl_calendar_events WHERE id=?', [$event]) === 0, 'No local author required on imported enrichment');
        $check($db->fetchOne('SELECT ct_unpublished_by_sync FROM tl_calendar_events WHERE id=?', [$event]) === '1', 'Teaser-only save preserves automatic-unpublication marker');
        $assertSource('Real teaser POST');
        $check((int) $db->fetchOne('SELECT endTime-startTime FROM tl_calendar_events WHERE id=?', [$event]) === 3600, 'Repeated DST clock times still represent different instants after native adjustTime');
        $check($db->fetchOne('SELECT alias FROM tl_calendar_events WHERE id=?', [$event]) === $before['alias'], 'Editorial save preserves stable alias');

        // Save callbacks run during validation, but unsuccessful forms must not clear sync visibility.
        $form = $request($edit);
        $invalid = self::controls($form[1], 'tl_calendar_events');
        $invalid['alias'] = '123';
        $invalid['published'] = '1';
        $invalid['save'] = '1';
        $invalidResult = $request($edit, $invalid);
        $check(in_array($invalidResult[0], [200, 422], true) && self::errors($invalidResult[1]) !== '', 'Native invalid alias produces form validation error HTTP '.$invalidResult[0].self::errors($invalidResult[1]));
        $check($db->fetchOne('SELECT ct_unpublished_by_sync FROM tl_calendar_events WHERE id=?', [$event]) === '1', 'Failed form preserves automatic-unpublication marker');
        $assertSource('Invalid POST');

        // Reset only the synthetic publication fixture in case the core retained independent fields.
        $db->update('tl_calendar_events', ['published' => 0, 'ct_unpublished_by_sync' => '1'], ['id' => $event]);
        $toggle = '/contao?do=calendar&table=tl_calendar_events&act=toggle&field=published&id='.$event;
        $toggled = $request($toggle);
        $check($toggled[0] < 400 && (bool) $db->fetchOne('SELECT published FROM tl_calendar_events WHERE id=?', [$event]), 'Native eye action publishes the imported event HTTP '.$toggled[0]);
        $check($db->fetchOne('SELECT ct_unpublished_by_sync FROM tl_calendar_events WHERE id=?', [$event]) === '', 'Successful native eye action clears sync visibility marker');
        $hidden = $request($toggle);
        $check($hidden[0] < 400 && !(bool) $db->fetchOne('SELECT published FROM tl_calendar_events WHERE id=?', [$event]), 'Native eye action hides the imported event');
        $check($db->fetchOne('SELECT ct_unpublished_by_sync FROM tl_calendar_events WHERE id=?', [$event]) === '', 'Manual hide remains editorial');
        $assertSource('Native toggle');

        $form = $request($edit);
        $forged = self::controls($form[1], 'tl_calendar_events');
        $forged['title'] = 'Forged source title';
        $forged['startDate'] = str_replace('2026', '2027', (string) $forged['startDate']);
        $forged['ct_sync_key'] = 'forged';
        $forged['ct_series_template_id'] = 0;
        $forged['save'] = '1';
        $forgedResult = $request($edit, $forged);
        $check(in_array($forgedResult[0], [200, 302, 303, 403, 422], true), 'Forged source POST safely handled HTTP '.$forgedResult[0]);
        $assertSource('Forged POST');

        foreach (['copy', 'delete', 'cut'] as $action) {
            $result = $request($base.'&act='.$action.'&pid='.$calendar.'&mode=2', ['FORM_SUBMIT' => 'tl_calendar_events']);
            $check($result[0] === 403, 'Direct imported '.$action.' denied with valid CSRF HTTP '.$result[0]);
        }
        $check((int) $db->fetchOne('SELECT COUNT(*) FROM tl_content WHERE pid=? AND ptable=?', [$event, 'tl_calendar_events']) === 1, 'Event editorial content survives attempted destructive actions');
        $check((int) $db->fetchOne('SELECT COUNT(*) FROM tl_content WHERE pid=? AND ptable=?', [$series, 'tl_ct_series_templates']) === 1, 'Shared editorial content survives attempted destructive actions');
        $assertSource('Destructive actions');
    }

    /** Collect exactly the successful controls a browser submits, including readonly fields. */
    private static function controls(string $html, string $formId): array
    {
        $document = new \DOMDocument();
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $pairs = [];
        foreach ($xpath->query('//form[@id="'.$formId.'"]//*[@name]') as $control) {
            if ($control->hasAttribute('disabled')) continue;
            $name = $control->getAttribute('name');
            $type = $control->getAttribute('type');
            if (in_array($type, ['submit','button'], true) || (in_array($type, ['checkbox','radio'], true) && !$control->hasAttribute('checked'))) continue;
            if ($control->tagName === 'textarea') $values = [$control->textContent];
            elseif ($control->tagName === 'select') {
                $values = [];
                foreach ($control->getElementsByTagName('option') as $option) if ($option->hasAttribute('selected')) $values[] = $option->getAttribute('value');
                if ($values === [] && $control->getElementsByTagName('option')->length) $values[] = $control->getElementsByTagName('option')->item(0)->getAttribute('value');
            } else $values = [$control->getAttribute('value')];
            foreach ($values as $value) $pairs[] = rawurlencode($name).'='.rawurlencode($value);
        }
        parse_str(implode('&', $pairs), $post);
        if (($post['FORM_SUBMIT'] ?? null) !== $formId || !isset($post['REQUEST_TOKEN'])) throw new \RuntimeException('Expected a native form with CSRF field.');

        return $post;
    }

    private static function errors(string $html): string
    {
        preg_match_all('~<(?:p|div)[^>]*class="[^"]*(?:tl_error|error)[^"]*"[^>]*>(.*?)</(?:p|div)>~s', $html, $errors);

        return $errors[1] ? '; validation: '.mb_substr(trim(strip_tags(implode(' ', $errors[1]))), 0, 300) : '';
    }
}
