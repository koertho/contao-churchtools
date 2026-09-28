<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\ContaoManager;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Koertho\ChurchToolsBundle\ChurchToolsBundle;

final class Plugin implements BundlePluginInterface, \Contao\ManagerPlugin\Routing\RoutingPluginInterface
{
    public function getRouteCollection(\Symfony\Component\Config\Loader\LoaderResolverInterface $resolver, \Symfony\Component\HttpKernel\KernelInterface $kernel): ?\Symfony\Component\Routing\RouteCollection
    {
        return $resolver->resolve(__DIR__.'/../Controller/', 'attribute')->load(__DIR__.'/../Controller/', 'attribute');
    }

    public function getBundles(ParserInterface $parser): array
    {
        return [BundleConfig::create(ChurchToolsBundle::class)->setLoadAfter([ContaoCoreBundle::class])];
    }
}
