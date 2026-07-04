<?php

namespace DrupalQuick\Tests\Unit\Config;

use DrupalQuick\Config\PresetDiscovery;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DrupalQuick\Config\PresetDiscovery
 */
final class PresetDiscoveryTest extends TestCase {

  private const FIXTURES = __DIR__ . '/../../fixtures/starterkits';

  public function testReadsTheDqManifest(): void {
    [$names, $default] = PresetDiscovery::discover(self::FIXTURES . '/with-manifest');

    $this->assertSame(['minimal', 'corporate', 'geometric'], $names);
    $this->assertSame('geometric', $default);
  }

  public function testFallsBackWhenPackageJsonIsMissing(): void {
    [$names, $default] = PresetDiscovery::discover(self::FIXTURES . '/does-not-exist');

    $this->assertSame(['minimal', 'corporate'], $names);
    $this->assertSame('minimal', $default);
  }

  public function testFallsBackWhenTheDqBlockIsAbsent(): void {
    [$names, $default] = PresetDiscovery::discover(self::FIXTURES . '/no-dq-block');

    $this->assertSame(['minimal', 'corporate'], $names);
    $this->assertSame('minimal', $default);
  }

  public function testUnknownDefaultIsCoercedToTheFirstPreset(): void {
    [$names, $default] = PresetDiscovery::discover(self::FIXTURES . '/bad-default');

    $this->assertSame(['alpha', 'beta'], $names);
    $this->assertSame('alpha', $default);
  }

}
