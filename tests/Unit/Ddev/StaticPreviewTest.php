<?php

namespace DrupalQuick\Tests\Unit\Ddev;

use DrupalQuick\Ddev\StaticPreview;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DrupalQuick\Ddev\StaticPreview
 */
final class StaticPreviewTest extends TestCase {

  private const CONFIG = <<<YAML
    name: quickthe-me
    type: drupal
    docroot: web
    php_version: "8.4"
    additional_hostnames: []
    YAML;

  public function testProjectNameParsesPlainAndQuotedForms(): void {
    $this->assertSame('quickthe-me', StaticPreview::projectName(self::CONFIG));
    $this->assertSame('my-site', StaticPreview::projectName("name: \"my-site\"\n"));
    $this->assertSame('my.site2', StaticPreview::projectName("name: 'my.site2'\n"));
  }

  public function testProjectNameIsNullWhenAbsentAndIgnoresCommentsAndNesting(): void {
    $this->assertNull(StaticPreview::projectName("type: drupal\n"));
    // A commented-out or indented (nested) name: line is not the project name.
    $this->assertNull(StaticPreview::projectName("# name: nope\n  name: nested\n"));
  }

  public function testProjectTldDefaultsAndParses(): void {
    $this->assertSame('ddev.site', StaticPreview::projectTld(self::CONFIG));
    $this->assertSame('local.dev', StaticPreview::projectTld("project_tld: local.dev\n"));
  }

  public function testHostnameAndFqdn(): void {
    $this->assertSame('static.quickthe-me', StaticPreview::hostname(self::CONFIG));
    $this->assertSame('static.quickthe-me.ddev.site', StaticPreview::fqdn(self::CONFIG));
    $this->assertSame('static.a.local.dev', StaticPreview::fqdn("name: a\nproject_tld: local.dev\n"));
    $this->assertNull(StaticPreview::hostname('type: drupal'));
    $this->assertNull(StaticPreview::fqdn('type: drupal'));
  }

  public function testIsManaged(): void {
    // Absent file: writable. Marked file: still managed. Unmarked: user-owned.
    $this->assertTrue(StaticPreview::isManaged(NULL));
    $this->assertTrue(StaticPreview::isManaged("something\n" . StaticPreview::MARKER . ": note\nmore"));
    $this->assertFalse(StaticPreview::isManaged("server {}\n"));
  }

  public function testNginxConfContent(): void {
    $conf = StaticPreview::nginxConf('static.my-site.ddev.site', '/var/www/html/html');
    $this->assertStringContainsString(StaticPreview::MARKER, $conf);
    $this->assertStringContainsString('root /var/www/html/html;', $conf);
    $this->assertStringContainsString('server_name static.my-site.ddev.site;', $conf);
    // Static-only vhost: no PHP handler, 404 for unknown paths.
    $this->assertStringNotContainsString('fastcgi', $conf);
    // $uri/index.html before $uri/: slashless URLs serve their directory
    // index with a 200 — a 301 would break cross-document view transitions.
    $this->assertStringContainsString('try_files $uri $uri/index.html $uri/ =404;', $conf);
    // HTML revalidates every load so re-exports show immediately; assets cache.
    $this->assertStringContainsString('add_header Cache-Control "no-cache";', $conf);
    $this->assertStringContainsString('expires 1M;', $conf);
  }

  public function testHostnamesOverrideContent(): void {
    $yaml = StaticPreview::hostnamesOverride('static.my-site');
    $this->assertStringContainsString(StaticPreview::MARKER, $yaml);
    $this->assertStringContainsString("additional_hostnames:\n  - static.my-site", $yaml);
  }

}
