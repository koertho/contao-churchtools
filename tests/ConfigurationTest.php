<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Tests;

use Koertho\ChurchToolsBundle\ChurchToolsBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ConfigurationTest extends TestCase
{
    public function testDefaultsAndZeroRetention(): void
    {
        $extension = (new ChurchToolsBundle())->getContainerExtension();
        $config = (new \Symfony\Component\Config\Definition\Processor())->processConfiguration(
            $extension->getConfiguration([], new ContainerBuilder()),
            [['sync' => ['past_months' => 0]]],
        );
        self::assertSame(0, $config['sync']['past_months']);
        self::assertSame(6, $config['sync']['future_months']);
    }

    public static function invalidConfig(): iterable
    {
        yield 'negative retention' => [['sync' => ['past_months' => -1]]];
        yield 'empty future window' => [['sync' => ['future_months' => 0]]];
        yield 'unknown key' => [['instance' => 'https://example.org']];
    }

    #[DataProvider('invalidConfig')]
    public function testInvalidConfiguration(array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new ChurchToolsBundle())->getContainerExtension()->load([$config], new ContainerBuilder());
    }
}
