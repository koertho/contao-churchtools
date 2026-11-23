<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\EventListener\DataContainer\CalendarEvents;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Image\ImageFactoryInterface;
use Contao\DataContainer;
use Contao\Image\ResizeConfiguration;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCallback(table: 'tl_calendar_events', target: 'fields.ct_image_src.input_field')]
final class FieldsCtImageSrcInputFieldListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ImageFactoryInterface $images,
        private readonly TranslatorInterface $translator,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    public function __invoke(DataContainer $dc): string
    {
        $path = $this->connection->fetchOne('SELECT f.path FROM tl_calendar_events e LEFT JOIN tl_files f ON f.uuid=e.ct_image_src AND f.type=? WHERE e.id=? AND e.ct_sync_key IS NOT NULL', ['file', $dc->id]);
        $label = htmlspecialchars($this->translator->trans('sourceImage', [], 'church_tools_backend'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $help = htmlspecialchars($this->translator->trans('sourceImageHelp', [], 'church_tools_backend'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $image = '';
        $project = realpath($this->projectDir);
        $file = is_string($path) ? realpath($this->projectDir.'/'.$path) : false;
        if ($project !== false && $file !== false && str_starts_with($file, $project.'/') && is_file($file)) {
            try {
                $url = $this->images->create($file, [400, 300, ResizeConfiguration::MODE_BOX])->getUrl($this->projectDir);
                $image = '<img src="'.htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" alt="'.$label.'">';
            } catch (\Throwable) {
                // A missing or unreadable preview must not prevent editing the event.
            }
        }
        if ($image === '') {
            $image = '<p>'.htmlspecialchars($this->translator->trans('sourceImageEmpty', [], 'church_tools_backend'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>';
        }

        return '<div class="widget clr ct-source-image ct-source-locked"><h3><label>'.$label.'</label></h3>'.$image.'<p class="tl_help tl_tip">'.$help.'</p></div>';
    }
}
