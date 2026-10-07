<?php

namespace App\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DependencyTest extends TestCase
{
    #[DataProvider('layers')]
    public function testCoreDependsOnlyOnInwardLayers(string $layer, array $allowedPrefixes): void
    {
        $directory = dirname(__DIR__, 2).'/src/'.$layer;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
        $violations = [];
        foreach ($files as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }
            foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
                if (!is_array($token) || !in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }
                $name = ltrim($token[1], '\\');
                // Native PHP types have no namespace; every namespaced dependency must point inward.
                if (!str_contains($name, '\\')) {
                    continue;
                }
                if (!array_any($allowedPrefixes, static fn (string $prefix): bool => str_starts_with($name, $prefix))) {
                    $violations[] = $file->getFilename().':'.$token[2].' -> '.$name;
                }
            }
        }

        self::assertSame([], $violations, implode("\n", $violations));
    }

    public static function layers(): iterable
    {
        yield 'domain' => ['Domain', ['App\\Domain\\']];
        yield 'application' => ['Application', ['App\\Domain\\', 'App\\Application\\']];
    }
}
