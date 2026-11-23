<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\EventListener\DataContainer\CtSeriesTemplates;

use Contao\BackendUser;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Image\ImageSizes;
use Symfony\Bundle\SecurityBundle\Security;

#[AsCallback(table: 'tl_ct_series_templates', target: 'fields.size.options')]
final class FieldsSizeOptionsListener
{
    public function __construct(private readonly Security $security, private readonly ImageSizes $imageSizes) {}

    public function __invoke(): array
    {
        $user = $this->security->getUser();

        return $user instanceof BackendUser ? $this->imageSizes->getOptionsForUser($user) : [];
    }
}
