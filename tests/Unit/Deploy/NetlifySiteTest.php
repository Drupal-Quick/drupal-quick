<?php

namespace DrupalQuick\Tests\Unit\Deploy;

use DrupalQuick\Deploy\NetlifySite;
use PHPUnit\Framework\TestCase;

final class NetlifySiteTest extends TestCase {

  public function testSiteIdFromLinkedState(): void {
    $this->assertSame(
      '48e870c2-bbb2-4425-98ad-52116d2c465d',
      NetlifySite::siteIdFromState('{"siteId": "48e870c2-bbb2-4425-98ad-52116d2c465d"}')
    );
  }

  /**
   * @dataProvider unlinkedStateProvider
   */
  public function testUnlinkedStatesReturnNull(?string $stateJson): void {
    $this->assertNull(NetlifySite::siteIdFromState($stateJson));
  }

  public static function unlinkedStateProvider(): array {
    return [
      'missing file' => [NULL],
      'empty file' => [''],
      'whitespace' => ["  \n"],
      'invalid json' => ['{nope'],
      'no siteId key' => ['{"other": "x"}'],
      'empty siteId' => ['{"siteId": ""}'],
      'non-string siteId' => ['{"siteId": 42}'],
    ];
  }

  public function testGenerateSiteNameSlugifiesAndSuffixes(): void {
    $this->assertSame('my-drupal-site-abc123', NetlifySite::generateSiteName('My Drupal_Site!', 'abc123'));
  }

  public function testGenerateSiteNameCollapsesAndTrimsHyphens(): void {
    $this->assertSame('a-b-abc123', NetlifySite::generateSiteName('--a---b--', 'abc123'));
  }

  public function testGenerateSiteNameFallsBackOnEmptyBase(): void {
    $this->assertSame('site-abc123', NetlifySite::generateSiteName('***', 'abc123'));
  }

  public function testGenerateSiteNameRandomSuffixWhenOmitted(): void {
    $name = NetlifySite::generateSiteName('proj');
    $this->assertMatchesRegularExpression('/^proj-[0-9a-f]{6}$/', $name);
  }

}
