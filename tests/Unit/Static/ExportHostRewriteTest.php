<?php

namespace DrupalQuick\Tests\Unit\Static;

use DrupalQuick\Static\ExportHostRewrite;
use PHPUnit\Framework\TestCase;

final class ExportHostRewriteTest extends TestCase {

  public function testRewritesEveryOccurrence(): void {
    $html = '<link rel="canonical" href="https://my-site.ddev.site/docs/x" />'
      . '<link rel="shortlink" href="https://my-site.ddev.site/node/3" />';
    $result = ExportHostRewrite::rewrite($html, 'https://my-site.ddev.site', 'https://example.com');
    $this->assertStringContainsString('https://example.com/docs/x', $result);
    $this->assertStringContainsString('https://example.com/node/3', $result);
    $this->assertStringNotContainsString('my-site.ddev.site', $result);
  }

  public function testNoOpWhenHostsAlreadyMatch(): void {
    $html = '<link href="https://example.com/x" />';
    $this->assertSame($html, ExportHostRewrite::rewrite($html, 'https://example.com', 'https://example.com'));
  }

  public function testNoOpWhenFromHostEmpty(): void {
    $html = '<link href="https://example.com/x" />';
    $this->assertSame($html, ExportHostRewrite::rewrite($html, '', 'https://example.com'));
  }

  public function testTrailingSlashesDoNotPreventMatch(): void {
    $html = '<link href="https://my-site.ddev.site/x" />';
    $result = ExportHostRewrite::rewrite($html, 'https://my-site.ddev.site/', 'https://example.com/');
    $this->assertSame('<link href="https://example.com/x" />', $result);
  }

  /**
   * @dataProvider rewritableExtensionsProvider
   */
  public function testIsRewritable(string $path, bool $expected): void {
    $this->assertSame($expected, ExportHostRewrite::isRewritable($path));
  }

  public static function rewritableExtensionsProvider(): array {
    return [
      'html' => ['index.html', TRUE],
      'xml (rss)' => ['rss.xml', TRUE],
      'json' => ['manifest.json', TRUE],
      'txt' => ['robots.txt', TRUE],
      'webmanifest' => ['site.webmanifest', TRUE],
      'png (binary)' => ['logo.png', FALSE],
      'woff2 (binary)' => ['Inter-400-latin.woff2', FALSE],
      'css' => ['main.css', FALSE],
      'js' => ['main.js', FALSE],
      'no extension' => ['LICENSE', FALSE],
    ];
  }

}
