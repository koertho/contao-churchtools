<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Calendar\Frontend\Twig;

use Contao\CalendarEventsModel;
use Contao\CoreBundle\ContaoCoreBundle;
use Contao\StringUtil;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Image\Studio\Studio;
use Doctrine\DBAL\Connection;
use Contao\CoreBundle\Twig\Interop\ContextFactory;
use Koertho\ContaoChurchTools\Calendar\Frontend\EventContentResolver;
use Koertho\ContaoChurchTools\Model\CtSeriesTemplatesModel;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Twig\TwigFilter;

final class ChurchToolsCalendarExtension extends AbstractExtension
{
    public function __construct(
        private readonly EventContentResolver $resolver,
        private readonly Connection $connection,
        private readonly Studio $studio,
        private readonly ContaoFramework $framework,
        private readonly ContextFactory $contextFactory,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('churchtools_event', $this->resolveReader(...))];
    }

    public function getFilters(): array
    {
        return [new TwigFilter('churchtools_source_text', static function (string $value): string {
            return str_replace(['{', '}'], ['&#123;', '&#125;'], htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }, ['is_safe' => ['html']])];
    }

    public function resolveReader(array $context, array|string|null $imageSize = null): array
    {
        $id = (int) ($context['id'] ?? 0);
        if ($id < 1) {
            return $context;
        }
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_calendar_events WHERE id = ?', [$id]);
        if (!$row || ($row['ct_sync_key'] ?? null) === null) {
            return $context;
        }
        $raw = $row + ['hasReader' => $context['hasReader'] ?? true];
        if (array_key_exists('href', $context)) $raw['href'] = $context['href'];
        $resolved = $this->resolver->resolve($raw);
        $context['ct_imported'] = true;
        foreach (['title', 'location', 'address'] as $field) {
            $value = (string) ($row[$field] ?? '');
            $context[$field] = version_compare(ContaoCoreBundle::getVersion(), '6.0', '<') ? html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $value;
        }
        if (array_key_exists('link', $context)) $context['link'] = $context['title'];
        $preserveFigure = ($resolved['ct_image_owner_table'] ?? '') === 'tl_calendar_events' && !empty($context['figure']) && $imageSize === null;
        foreach (['teaser', 'hasTeaser', 'details', 'hasDetails', 'addImage', 'singleSRC', 'size', 'fullsize', 'floating', 'overwriteMeta', 'alt', 'imageTitle', 'imageUrl', 'caption'] as $field) {
            if (array_key_exists($field, $resolved)) {
                $context[$field] = $resolved[$field];
            }
        }
        if (!empty($resolved['addImage']) && !$preserveFigure) {
            $size = $resolved['size'] ?: null;
            $override = $imageSize ?? $context['ct_module_image_size'] ?? null;
            if ($override) {
                $dimensions = StringUtil::deserialize($override, true);
                if (($dimensions[0] ?? 0) > 0 || ($dimensions[1] ?? 0) > 0 || is_numeric($dimensions[2] ?? null) || ($dimensions[2][0] ?? null) === '_') $size = $override;
            }
            $class = $resolved['ct_image_owner_table'] === 'tl_ct_series_templates' ? CtSeriesTemplatesModel::class : CalendarEventsModel::class;
            $owner = $this->framework->getAdapter($class)->findByPk($resolved['ct_image_owner_id']);
            $figure = $this->studio->createFigureBuilder()
                ->fromUuid($resolved['singleSRC'])
                ->setSize($size)
                ->setOverwriteMetadata($owner?->getOverwriteMetadata())
                ->enableLightbox((bool) ($resolved['fullsize'] ?? false))
                ->buildIfResourceExists();
            $template = (object) $context;
            $figure?->applyLegacyTemplateData($template, null, $resolved['floating'] ?: null);
            $context = get_object_vars($template);
        } elseif (empty($resolved['addImage'])) {
            $context['figure'] = null;
        }

        return $this->contextFactory->fromData($context);
    }
}
