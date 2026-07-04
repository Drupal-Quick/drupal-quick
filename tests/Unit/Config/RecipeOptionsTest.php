<?php

namespace DrupalQuick\Tests\Unit\Config;

use DrupalQuick\Config\RecipeOptions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * @covers \DrupalQuick\Config\RecipeOptions
 */
final class RecipeOptionsTest extends TestCase {

  /**
   * A typical recipe.yml input block (the blog recipe's).
   */
  private const INPUTS = [
    'items_per_page' => [
      'data_type' => 'integer',
      'description' => 'How many articles the writing view lists per page.',
      'default' => ['source' => 'value', 'value' => 30],
    ],
  ];

  private const CONFIG = <<<YAML
site:
  name: "My Site"

theme:
  machine_name: "my_theme"

recipes:
  - "core/recipes/standard"
  - "blog"
  - "project"

parameters:
  theme_design:
    primary_color: "#10b981"
YAML;

  // -------------------------------------------------------- activeLines()

  public function testActiveLinesRendersEachValueType(): void {
    $lines = RecipeOptions::activeLines([
      'per_page' => ['data_type' => 'integer', 'default' => ['value' => 30], 'description' => 'Rows.'],
      'title' => ['data_type' => 'string', 'default' => ['value' => 'Writing']],
      'enabled' => ['data_type' => 'boolean', 'default' => ['value' => TRUE]],
      'off' => ['data_type' => 'boolean', 'default' => ['value' => FALSE]],
      'unset' => ['data_type' => 'string'],
    ]);

    $this->assertSame([
      '    options:',
      '      per_page: 30   # Rows.',
      '      title: "Writing"',
      '      enabled: true',
      '      off: false',
      '      unset: ~',
    ], $lines);
  }

  public function testActiveLinesUncommentedParseUnderAMappingEntry(): void {
    // The rendered lines must be valid YAML at their stated indentation when
    // placed under a `- name:` entry — that is the whole uncomment contract.
    $yaml = "recipes:\n  - name: \"blog\"\n"
      . implode("\n", RecipeOptions::activeLines(self::INPUTS)) . "\n";
    $parsed = Yaml::parse($yaml);

    $this->assertSame(30, $parsed['recipes'][0]['options']['items_per_page']);
  }

  // ---------------------------------------------------- injectCommented()

  public function testInjectPromotesAStringEntryToMappingForm(): void {
    $lines = $this->inject(self::CONFIG);

    $this->assertContains('  - name: "blog"', $lines);
    $this->assertNotContains('  - "blog"', $lines);
    // Siblings are untouched.
    $this->assertContains('  - "core/recipes/standard"', $lines);
    $this->assertContains('  - "project"', $lines);
  }

  public function testInjectPlacesCommentedBlockDirectlyUnderTheEntry(): void {
    $lines = $this->inject(self::CONFIG);
    $at = array_search('  - name: "blog"', $lines, TRUE);

    $this->assertSame('#     options:', $lines[$at + 1]);
    $this->assertSame('#       items_per_page: 30   # How many articles the writing view lists per page.', $lines[$at + 2]);
    $this->assertSame('  - "project"', $lines[$at + 3]);
  }

  public function testFileStillParsesWithTheBlockCommented(): void {
    $parsed = Yaml::parse(implode("\n", $this->inject(self::CONFIG)));

    $this->assertSame(['name' => 'blog'], $parsed['recipes'][1]);
  }

  public function testUncommentingTheBlockYieldsValidOptions(): void {
    $out = implode("\n", $this->inject(self::CONFIG));
    // What a user does: strip the leading "# " from the injected lines only.
    $out = preg_replace('/^# (    options:|      \w)/m', '$1', $out);
    $parsed = Yaml::parse($out);

    $this->assertSame(30, $parsed['recipes'][1]['options']['items_per_page']);
  }

  public function testInjectUnderAnExistingMappingEntry(): void {
    $config = "recipes:\n  - name: \"blog\"\n\nparameters:\n  x: 1\n";
    $lines = $this->inject($config);

    $this->assertSame('#     options:', $lines[2]);
  }

  public function testInjectIsIdempotentOnItsOwnOutput(): void {
    $once = $this->inject(self::CONFIG);

    $this->assertSame($once, $this->injectLines($once));
  }

  public function testInjectPreservesUserEditedActiveOptions(): void {
    $edited = $this->inject(self::CONFIG);
    // The user uncommented and changed the value.
    $edited = str_replace(
      ['#     options:', '#       items_per_page: 30   # How many articles the writing view lists per page.'],
      ['    options:', '      items_per_page: 5'],
      $edited
    );

    $this->assertSame($edited, $this->injectLines($edited));
  }

  public function testUnknownRecipeKeyLeavesLinesUntouched(): void {
    $lines = explode("\n", self::CONFIG);

    $this->assertSame($lines, RecipeOptions::injectCommented($lines, 'nope', RecipeOptions::activeLines(self::INPUTS)));
  }

  public function testMissingRecipesBlockLeavesLinesUntouched(): void {
    $lines = ['site:', '  name: "x"'];

    $this->assertSame($lines, RecipeOptions::injectCommented($lines, 'blog', RecipeOptions::activeLines(self::INPUTS)));
  }

  public function testEntryFollowedByTopLevelCommentBlockStillMatches(): void {
    // The shipped template has a col-0 "# homepage:" comment block right after
    // the last recipe entry — comment lines must not end the recipes block.
    $config = <<<YAML
recipes:
  - "core/recipes/standard"
  - "blog"

# Homepage composition (optional).
# homepage:
#   blocks:
#     - "blog/recent"

parameters:
  x: 1
YAML;
    $lines = $this->inject($config);
    $at = array_search('  - name: "blog"', $lines, TRUE);

    $this->assertNotFalse($at);
    $this->assertSame('#     options:', $lines[$at + 1]);
    // The comment block after it is untouched.
    $this->assertContains('# homepage:', $lines);
  }

  /**
   * @return string[]
   */
  private function inject(string $config): array {
    return $this->injectLines(explode("\n", $config));
  }

  /**
   * @param string[] $lines
   * @return string[]
   */
  private function injectLines(array $lines): array {
    return RecipeOptions::injectCommented($lines, 'blog', RecipeOptions::activeLines(self::INPUTS));
  }

}
