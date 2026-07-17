<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Creatuity\Interception\Test\Unit\CompiledInterceptor;

use Creatuity\Interception\Generator\CompiledInterceptor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Locks the fix for https://github.com/creatuity/magento2-interceptors/issues/28
 *
 * The generated interceptor's `switch ($scope)` must run the GLOBAL plugin chain in its
 * `default` branch, so global plugins fire in CLI/primary and every scope that is not
 * explicitly enumerated (crontab, webapi_rest, webapi_soap, graphql, and any custom scope) -
 * exactly like a stock Magento interceptor whose PluginList merges global config into each
 * area. Only scopes whose merged chain differs from global get an explicit `case`.
 *
 * The check targets the private CompiledInterceptor::getScopeCasesFromConfig(), which owns
 * that mapping, so it does not need a bootstrapped plugin list.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ScopeCasesTest extends TestCase
{
    /**
     * @param array $config    scope => resolved plugin chain (opaque here, compared by ==)
     * @param array $expected  ['default' => <conf>, 'cases' => [scope => <conf>, ...]]
     * @dataProvider scopeCasesProvider
     */
    #[DataProvider('scopeCasesProvider')]
    public function testGlobalChainDrivesDefaultBranch(array $config, array $expected)
    {
        $reflection = new ReflectionClass(CompiledInterceptor::class);
        /** @var CompiledInterceptor $interceptor */
        $interceptor = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('getScopeCasesFromConfig');
        if (PHP_VERSION_ID < 80100) {
            // No-op (and deprecated) since PHP 8.1; still required on 7.4/8.0.
            $method->setAccessible(true);
        }

        $cases = $method->invoke($interceptor, $config);

        // The last emitted branch is always `default:` and must carry the expected chain.
        $default = $cases[count($cases) - 1];
        $this->assertSame(["\tdefault:"], $default['cases'], 'The last branch must be `default:`.');
        $this->assertEquals(
            $expected['default'],
            $default['conf'],
            'The default branch must carry the global (base) plugin chain.'
        );

        // Every other branch must be an explicit `case`, one per scope that differs from global.
        $emitted = [];
        foreach (array_slice($cases, 0, -1) as $case) {
            foreach ($case['cases'] as $label) {
                $this->assertMatchesCase($label);
                $scope = $this->scopeFromCaseLabel($label);
                $emitted[$scope] = $case['conf'];
            }
        }
        $this->assertEquals(
            $expected['cases'],
            $emitted,
            'Only scopes whose chain differs from global may be emitted as explicit cases.'
        );
    }

    /**
     * @return array[]
     */
    public static function scopeCasesProvider()
    {
        return [
            // #28 core: a globally declared plugin (A) with an adminhtml-only addition (B).
            // Global's chain [A] must land in `default` so CLI/primary/etc. still run it;
            // only adminhtml (which differs) gets an explicit case.
            'global plugin, adminhtml adds one' => [
                'config' => [
                    'global'      => ['A'],
                    'frontend'    => ['A'],
                    'adminhtml'   => ['A', 'B'],
                    'crontab'     => ['A'],
                    'webapi_rest' => ['A'],
                    'primary'     => ['A'],
                ],
                'expected' => [
                    'default' => ['A'],
                    'cases'   => ['adminhtml' => ['A', 'B']],
                ],
            ],
            // All scopes identical to global -> no explicit case, default carries the chain.
            'uniform global plugin' => [
                'config' => [
                    'global'    => ['A'],
                    'frontend'  => ['A'],
                    'adminhtml' => ['A'],
                    'primary'   => ['A'],
                ],
                'expected' => [
                    'default' => ['A'],
                    'cases'   => [],
                ],
            ],
            // Genuinely area-only plugin (nothing global): default stays parent-direct ([]),
            // the area keeps its explicit case. No regression for real area-scoped plugins.
            'frontend-only plugin' => [
                'config' => [
                    'global'    => [],
                    'frontend'  => ['F'],
                    'adminhtml' => [],
                    'primary'   => [],
                ],
                'expected' => [
                    'default' => [],
                    'cases'   => ['frontend' => ['F']],
                ],
            ],
            // No global key at all -> fall back to primary as the default chain.
            'fallback to primary when global missing' => [
                'config' => [
                    'primary'  => ['P'],
                    'frontend' => ['P', 'F'],
                ],
                'expected' => [
                    'default' => ['P'],
                    'cases'   => ['frontend' => ['P', 'F']],
                ],
            ],
        ];
    }

    /**
     * @param string $label
     */
    private function assertMatchesCase($label)
    {
        $this->assertSame(
            1,
            preg_match("/^\tcase '[^']+':$/", $label),
            "Non-default branches must be `case '<scope>':`, got: {$label}"
        );
    }

    /**
     * @param string $label
     * @return string
     */
    private function scopeFromCaseLabel($label)
    {
        preg_match("/^\tcase '([^']+)':$/", $label, $matches);
        return $matches[1];
    }
}
