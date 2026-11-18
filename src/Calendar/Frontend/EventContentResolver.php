<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Calendar\Frontend;

use Contao\ContentModel;
use Contao\Controller;
use Contao\CoreBundle\Cache\CacheTagManager;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Template;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/** Resolves editorial data without writing it back to the imported event. */
final class EventContentResolver implements ResetInterface
{
    private const IMAGE_FIELDS = ['addImage', 'singleSRC', 'size', 'fullsize', 'floating', 'overwriteMeta', 'alt', 'imageTitle', 'imageUrl', 'caption'];

    private array $series = [];
    private array $elements = [];
    private array $rendered = [];
    private array $files = [];
    private ?Request $request = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly ContaoFramework $framework,
        private readonly CacheTagManager $cacheTags,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly ?RequestStack $requests = null,
    ) {
    }

    public function reset(): void
    {
        $this->series = $this->elements = $this->rendered = $this->files = [];
    }

    public function resolve(array $event): array
    {
        if ($this->requests !== null && $this->request !== $this->requests->getMainRequest()) {
            $this->reset();
            $this->request = $this->requests->getMainRequest();
        }
        if (($event['ct_sync_key'] ?? null) === null) {
            return $event;
        }
        $seriesId = (int) ($event['ct_series_template_id'] ?? 0);
        $series = [];
        if ($seriesId > 0) {
            // Tag even an empty/missing template so its later additions invalidate this response.
            $this->cacheTags->tagWith('contao.db.tl_ct_series_templates.'.$seriesId);
            if (!array_key_exists($seriesId, $this->series)) {
                $this->series[$seriesId] = $this->connection->fetchAssociative('SELECT * FROM tl_ct_series_templates WHERE id = ?', [$seriesId]) ?: [];
            }
            $series = $this->series[$seriesId];
        }
        if (trim((string) ($event['teaser'] ?? '')) === '') {
            $event['teaser'] = $series['teaser'] ?? '';
            if (trim((string) $event['teaser']) === '') {
                $text = htmlspecialchars((string) ($event['ct_description'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $text = str_replace(['{', '}'], ['&#123;', '&#125;'], $text);
                $event['teaser'] = $text === '' ? '' : '<p>'.str_replace("\n", '<br>', str_replace(["\r\n", "\r"], "\n", $text)).'</p>';
            }
        }
        $event['hasTeaser'] = trim((string) ($event['teaser'] ?? '')) !== '';
        if (!$this->hasImage($event)) {
            foreach (self::IMAGE_FIELDS as $field) {
                $event[$field] = $series[$field] ?? match ($field) { 'singleSRC', 'size' => null, default => '' };
            }
            if ($this->hasImage($event)) {
                $event['ct_image_owner_table'] = 'tl_ct_series_templates';
                $event['ct_image_owner_id'] = $seriesId;
            } else {
                foreach (self::IMAGE_FIELDS as $field) {
                    $event[$field] = match ($field) { 'singleSRC', 'size' => null, default => '' };
                }
                $event['addImage'] = '1';
                $event['singleSRC'] = $event['ct_image_src'] ?? null;
                if ($this->hasImage($event)) {
                    $event['ct_image_owner_table'] = 'ct_image';
                    $event['ct_image_owner_id'] = (int) $event['id'];
                } else {
                    $event['addImage'] = '';
                }
            }
        } else {
            $event['ct_image_owner_table'] = 'tl_calendar_events';
            $event['ct_image_owner_id'] = (int) $event['id'];
        }
        $id = (int) $event['id'];
        $event['details'] = Template::once(function () use ($id, $seriesId): string {
            return $this->render('tl_calendar_events', $id).($seriesId > 0 ? $this->render('tl_ct_series_templates', $seriesId) : '');
        });
        $hasUrl = array_key_exists('href', $event) ? !empty($event['href']) : !empty($event['hasReader']);
        $hasTeaser = $event['hasTeaser'];
        $event['hasDetails'] = Template::once(function () use ($id, $seriesId, $hasUrl, $hasTeaser): bool {
            return $hasUrl && ($hasTeaser || $this->publishedElements('tl_calendar_events', $id) !== [] || ($seriesId > 0 && $this->publishedElements('tl_ct_series_templates', $seriesId) !== []));
        });

        return $event;
    }

    private function hasImage(array $data): bool
    {
        if (empty($data['addImage']) || empty($data['singleSRC'])) {
            return false;
        }
        $uuid = $data['singleSRC'];
        if (!array_key_exists($uuid, $this->files)) {
            $path = $this->connection->fetchOne('SELECT path FROM tl_files WHERE uuid = ? AND type = ?', [$uuid, 'file']);
            $this->files[$uuid] = is_string($path) && is_file($this->projectDir.'/'.$path);
        }

        return $this->files[$uuid];
    }

    private function publishedElements(string $table, int $id): array
    {
        $key = $table.':'.$id;
        if (!array_key_exists($key, $this->elements)) {
            $models = $this->framework->getAdapter(ContentModel::class)->findPublishedByPidAndTable($id, $table);
            $this->elements[$key] = $models === null ? [] : (is_array($models) ? $models : iterator_to_array($models, false));
        }

        return $this->elements[$key];
    }

    private function render(string $table, int $id): string
    {
        $key = $table.':'.$id;
        if (!array_key_exists($key, $this->rendered)) {
            $html = '';
            foreach ($this->publishedElements($table, $id) as $element) {
                $html .= $this->framework->getAdapter(Controller::class)->getContentElement($element);
            }
            $this->rendered[$key] = $html;
        }

        return $this->rendered[$key];
    }
}
