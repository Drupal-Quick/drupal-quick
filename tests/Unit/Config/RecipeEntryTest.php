<?php

namespace DrupalQuick\Tests\Unit\Config;

use DrupalQuick\Config\RecipeEntry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DrupalQuick\Config\RecipeEntry
 */
final class RecipeEntryTest extends TestCase {

  public function testStringEntryHasNoOptions(): void {
    $this->assertSame(['blog', []], RecipeEntry::normalize('blog'));
    $this->assertSame(['core/recipes/standard', []], RecipeEntry::normalize('core/recipes/standard'));
  }

  public function testNameFormCarriesItsOptions(): void {
    $this->assertSame(
      ['blog', ['items_per_page' => 10]],
      RecipeEntry::normalize(['name' => 'blog', 'options' => ['items_per_page' => 10]])
    );
  }

  public function testNameFormWithoutOptions(): void {
    $this->assertSame(['blog', []], RecipeEntry::normalize(['name' => 'blog']));
  }

  public function testInlineSpecKeepsPackageAndUrlButNotOptions(): void {
    $entry = ['package' => 'you/recipe-x', 'url' => 'https://example.com/x', 'options' => ['a' => 1]];

    [$ref, $options] = RecipeEntry::normalize($entry);

    $this->assertSame(['package' => 'you/recipe-x', 'url' => 'https://example.com/x'], $ref);
    $this->assertSame(['a' => 1], $options);
  }

  public function testNonArrayOptionsAreCoercedToEmpty(): void {
    $this->assertSame(['blog', []], RecipeEntry::normalize(['name' => 'blog', 'options' => 'oops']));
  }

  public function testPackageForResolvesRegistryKeyInlineSpecAndPath(): void {
    $registry = ['blog' => ['package' => 'drupal-quick/recipe-blog']];

    $this->assertSame('drupal-quick/recipe-blog', RecipeEntry::packageFor('blog', $registry));
    $this->assertSame('you/recipe-x', RecipeEntry::packageFor(['package' => 'you/recipe-x'], $registry));
    $this->assertNull(RecipeEntry::packageFor('core/recipes/standard', $registry));
  }

}
