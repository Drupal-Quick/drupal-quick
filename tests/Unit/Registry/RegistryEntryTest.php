<?php

namespace DrupalQuick\Tests\Unit\Registry;

use DrupalQuick\Registry\RegistryEntry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DrupalQuick\Registry\RegistryEntry
 */
final class RegistryEntryTest extends TestCase {

  private const COMPOSER = [
    'name' => 'drupal-quick/recipe-blog',
    'type' => 'drupal-recipe',
    'extra' => [
      'dq' => [
        'recipe' => [
          'key' => 'blog',
          'label' => 'Blog — Keywords on Article',
          'blocks' => [
            'recent' => ['plugin' => 'views_block:writing-block_1', 'label' => 'Recent writing'],
          ],
        ],
      ],
    ],
  ];

  public function testBuildsAFullEntry(): void {
    $built = RegistryEntry::fromComposer(self::COMPOSER, 'https://github.com/Drupal-Quick/recipe-blog');

    $this->assertSame('blog', $built['key']);
    $this->assertSame([
      'label' => 'Blog — Keywords on Article',
      'package' => 'drupal-quick/recipe-blog',
      'url' => 'https://github.com/Drupal-Quick/recipe-blog',
      'blocks' => [
        'recent' => ['plugin' => 'views_block:writing-block_1', 'label' => 'Recent writing'],
      ],
    ], $built['entry']);
  }

  public function testNonRecipePackagesAreSkipped(): void {
    $this->assertNull(RegistryEntry::fromComposer(['name' => 'x/y', 'type' => 'drupal-theme'], ''));
    $this->assertNull(RegistryEntry::fromComposer(NULL, ''));
  }

  public function testRecipesWithoutCatalogMetadataAreSkipped(): void {
    $this->assertNull(RegistryEntry::fromComposer(['name' => 'x/y', 'type' => 'drupal-recipe'], ''));
  }

  public function testLabelFallsBackToTheKey(): void {
    $composer = ['name' => 'x/y', 'type' => 'drupal-recipe', 'extra' => ['dq' => ['recipe' => ['key' => 'y']]]];

    $this->assertSame('y', RegistryEntry::fromComposer($composer, '')['entry']['label']);
  }

  public function testRecipeInputsAreSummarisedIntoOptions(): void {
    $recipeData = [
      'input' => [
        'items_per_page' => [
          'data_type' => 'integer',
          'description' => 'How many articles per page.',
          'default' => ['source' => 'value', 'value' => 30],
        ],
      ],
    ];

    $built = RegistryEntry::fromComposer(self::COMPOSER, '', $recipeData);

    $this->assertSame([
      'items_per_page' => [
        'type' => 'integer',
        'description' => 'How many articles per page.',
        'default' => 30,
      ],
    ], $built['entry']['options']);
  }

  public function testRecipeWithoutInputsGetsNoOptionsKey(): void {
    $built = RegistryEntry::fromComposer(self::COMPOSER, '', ['name' => 'Blog']);

    $this->assertArrayNotHasKey('options', $built['entry']);
  }

  public function testNormalizeGitUrl(): void {
    $this->assertSame('https://github.com/Org/repo', RegistryEntry::normalizeGitUrl('git@github.com:Org/repo.git'));
    $this->assertSame('https://github.com/Org/repo', RegistryEntry::normalizeGitUrl("https://github.com/Org/repo.git\n"));
    $this->assertSame('https://github.com/Org/repo', RegistryEntry::normalizeGitUrl('https://github.com/Org/repo'));
  }

}
