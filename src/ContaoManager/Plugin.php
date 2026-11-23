<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\ContaoManager;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\CalendarBundle\ContaoCalendarBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Koertho\ContaoChurchTools\ChurchToolsBundle;

final class Plugin implements BundlePluginInterface
{
    public function getBundles(ParserInterface $parser): array
    {
        return [BundleConfig::create(ChurchToolsBundle::class)->setLoadAfter([ContaoCoreBundle::class, ContaoCalendarBundle::class])];
    }
}
