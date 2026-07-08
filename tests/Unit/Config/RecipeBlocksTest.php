<?php

namespace DrupalQuick\Tests\Unit\Config;

use DrupalQuick\Config\RecipeBlocks;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DrupalQuick\Config\RecipeBlocks
 */
final class RecipeBlocksTest extends TestCase {

  private static array $twoBlocks = [
    'blog/recent'  => ['label' => 'Recent writing', 'plugin' => 'views_block:writing-block_1'],
    'project/grid' => ['label' => 'Projects grid',  'plugin' => 'views_block:projects-block_1'],
  ];

  // ------------------------------------------------------------------ catalog

  public function testCommentedCatalogEmptyReturnsEmpty(): void {
    $this->assertSame([], RecipeBlocks::commentedCatalog([]));
  }

  public function testCommentedCatalogContainsMarker(): void {
    $lines = RecipeBlocks::commentedCatalog(self::$twoBlocks);
    $this->assertStringStartsWith('# ── Available recipe blocks', $lines[0]);
  }

  public function testCommentedCatalogListsBlockKeys(): void {
    $lines = RecipeBlocks::commentedCatalog(self::$twoBlocks);
    $joined = implode("\n", $lines);
    $this->assertStringContainsString('blog/recent', $joined);
    $this->assertStringContainsString('project/grid', $joined);
  }

  public function testCommentedCatalogListsLabels(): void {
    $lines  = RecipeBlocks::commentedCatalog(self::$twoBlocks);
    $joined = implode("\n", $lines);
    $this->assertStringContainsString('Recent writing', $joined);
    $this->assertStringContainsString('Projects grid', $joined);
  }

  public function testCommentedCatalogListsPlugins(): void {
    $lines  = RecipeBlocks::commentedCatalog(self::$twoBlocks);
    $joined = implode("\n", $lines);
    $this->assertStringContainsString('views_block:writing-block_1', $joined);
  }

  public function testCommentedCatalogEndsWithCommentedHomepageYaml(): void {
    $lines = RecipeBlocks::commentedCatalog(self::$twoBlocks);
    $this->assertSame('#     - "project/grid"', end($lines));
  }

  public function testCommentedCatalogSingleBlock(): void {
    $blocks = ['blog/recent' => ['label' => 'Recent writing', 'plugin' => 'views_block:writing-block_1']];
    $lines  = RecipeBlocks::commentedCatalog($blocks);
    $joined = implode("\n", $lines);
    $this->assertStringContainsString('#     - "blog/recent"', $joined);
    $this->assertStringNotContainsString('project/grid', $joined);
  }

  // ------------------------------------------------------------------ inject: skip cases

  public function testInjectCatalogEmptyBlocksReturnsUnchanged(): void {
    $lines = ['recipes:', '  - "blog"', '', 'parameters:'];
    $this->assertSame($lines, RecipeBlocks::injectCatalog($lines, []));
  }

  public function testInjectCatalogSkipsWhenHomepageAlreadyActive(): void {
    $lines = [
      'recipes:',
      '  - "blog"',
      '',
      'homepage:',
      '  blocks:',
      '    - "blog/recent"',
      '',
      'parameters:',
    ];
    $result = RecipeBlocks::injectCatalog($lines, self::$twoBlocks);
    $this->assertSame($lines, $result);
  }

  // ------------------------------------------------------------------ inject: template form

  public function testInjectCatalogReplacesTemplatePlaceholder(): void {
    $lines = [
      'recipes:',
      '  - "blog"',
      '',
      '# Homepage composition (optional). Pick which blocks...',
      '# When set, the front page becomes the composed blocks.',
      '# homepage:',
      '#   blocks:',
      '#     - "blog/recent"',
      '#     - "project/grid"',
      '',
      'parameters:',
    ];
    $result = RecipeBlocks::injectCatalog($lines, self::$twoBlocks);
    $joined = implode("\n", $result);

    // The marker should now be present.
    $this->assertStringContainsString('── Available recipe blocks', $joined);
    // The original prose comment should be gone.
    $this->assertStringNotContainsString('Homepage composition (optional)', $joined);
    // The block keys should appear in the catalog.
    $this->assertStringContainsString('blog/recent', $joined);
    $this->assertStringContainsString('project/grid', $joined);
    // parameters: must still be present.
    $this->assertStringContainsString('parameters:', $joined);
  }

  public function testInjectCatalogPreservesBlankLineSeparationAfterReplacement(): void {
    $lines = [
      'recipes:',
      '  - "blog"',
      '',
      '# homepage:',
      '#   blocks:',
      '',
      'parameters:',
    ];
    $result = RecipeBlocks::injectCatalog($lines, self::$twoBlocks);
    // Find the parameters: line and check the line before it is blank.
    $paramIdx = array_search('parameters:', $result, TRUE);
    $this->assertNotFalse($paramIdx);
    $this->assertSame('', $result[$paramIdx - 1]);
  }

  // ------------------------------------------------------------------ inject: idempotency

  public function testInjectCatalogIsIdempotent(): void {
    $lines = [
      'recipes:',
      '  - "blog"',
      '',
      'parameters:',
    ];
    $once  = RecipeBlocks::injectCatalog($lines, self::$twoBlocks);
    $twice = RecipeBlocks::injectCatalog($once, self::$twoBlocks);
    $this->assertSame($once, $twice);
  }

  public function testInjectCatalogUpdatesOnNewRecipe(): void {
    $initial = [
      'recipes:',
      '  - "blog"',
      '',
      'parameters:',
    ];
    $oneBlock = [
      'blog/recent' => ['label' => 'Recent writing', 'plugin' => 'views_block:writing-block_1'],
    ];
    $afterFirst = RecipeBlocks::injectCatalog($initial, $oneBlock);

    // Now a second recipe is added and dq-install runs again.
    $result = RecipeBlocks::injectCatalog($afterFirst, self::$twoBlocks);
    $joined = implode("\n", $result);

    // Both blocks should now appear.
    $this->assertStringContainsString('blog/recent', $joined);
    $this->assertStringContainsString('project/grid', $joined);

    // The MARKER should appear exactly once.
    $this->assertSame(1, substr_count($joined, '── Available recipe blocks'));
  }

  // ------------------------------------------------------------------ inject: fresh insert

  public function testInjectCatalogInsertsBeforeParameters(): void {
    $lines = [
      'recipes:',
      '  - "blog"',
      '',
      'parameters:',
      '  theme_design:',
    ];
    $result  = RecipeBlocks::injectCatalog($lines, self::$twoBlocks);
    $joined  = implode("\n", $result);
    $markerPos = strpos($joined, '── Available recipe blocks');
    $paramPos  = strpos($joined, 'parameters:');
    $this->assertLessThan($paramPos, $markerPos);
  }

  public function testInjectCatalogInsertsBeforeStatic(): void {
    $lines = [
      'recipes:',
      '  - "blog"',
      '',
      'static:',
      '  target: netlify',
    ];
    $result  = RecipeBlocks::injectCatalog($lines, self::$twoBlocks);
    $joined  = implode("\n", $result);
    $markerPos = strpos($joined, '── Available recipe blocks');
    $staticPos = strpos($joined, 'static:');
    $this->assertLessThan($staticPos, $markerPos);
  }

  public function testInjectCatalogAppendsWhenNoAnchor(): void {
    $lines  = ['recipes:', '  - "blog"'];
    $result = RecipeBlocks::injectCatalog($lines, self::$twoBlocks);
    $this->assertGreaterThan(count($lines), count($result));
    $this->assertStringContainsString('── Available recipe blocks', implode("\n", $result));
  }

}
