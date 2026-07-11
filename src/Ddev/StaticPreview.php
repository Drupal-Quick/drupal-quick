<?php

namespace DrupalQuick\Ddev;

/**
 * Pure logic for `dq:static --ddev-preview`: derives the preview hostname
 * from a project's .ddev/config.yaml and renders the two DDEV config files
 * that serve the static export as a second vhost beside the live site —
 * an nginx server block rooted at the export directory, and a DDEV
 * config.*.yaml override adding the hostname.
 *
 * Kept free of Drush/Drupal so it is unit-testable (tests/Unit/Ddev). File
 * IO stays in the command; this class only parses and renders strings.
 */
final class StaticPreview {

  /**
   * Marker identifying files this tool owns. A generated file keeps being
   * rewritten on every `dq:static --ddev-preview` run while the marker is
   * present; remove the marker line to take ownership (the tool then leaves
   * the file untouched). Mirrors DDEV's #ddev-generated convention without
   * borrowing its exact marker, which DDEV itself manages.
   */
  public const MARKER = '#dq-generated';

  /**
   * The DDEV project name from a .ddev/config.yaml, or NULL if not found.
   */
  public static function projectName(string $configYaml): ?string {
    if (preg_match('/^name:\s*["\']?([A-Za-z0-9][A-Za-z0-9.-]*)["\']?\s*$/m', $configYaml, $m)) {
      return $m[1];
    }
    return NULL;
  }

  /**
   * The project TLD (DDEV default: ddev.site) from a .ddev/config.yaml.
   */
  public static function projectTld(string $configYaml): string {
    if (preg_match('/^project_tld:\s*["\']?([A-Za-z0-9][A-Za-z0-9.-]*)["\']?\s*$/m', $configYaml, $m)) {
      return $m[1];
    }
    return 'ddev.site';
  }

  /**
   * The additional hostname to register ("static.<project>"), or NULL when
   * the project name cannot be derived.
   */
  public static function hostname(string $configYaml): ?string {
    $name = self::projectName($configYaml);
    return $name === NULL ? NULL : "static.{$name}";
  }

  /**
   * The fully qualified preview domain ("static.<project>.<tld>").
   */
  public static function fqdn(string $configYaml): ?string {
    $hostname = self::hostname($configYaml);
    return $hostname === NULL ? NULL : $hostname . '.' . self::projectTld($configYaml);
  }

  /**
   * Whether a preview file may be (re)written: it does not exist yet, or it
   * still carries the managed-file marker.
   */
  public static function isManaged(?string $existingContents): bool {
    return $existingContents === NULL || str_contains($existingContents, self::MARKER);
  }

  /**
   * Renders .ddev/nginx_full/static.conf — a static-only server block (no
   * PHP handler) rooted at the export directory.
   *
   * @param string $fqdn
   *   The preview domain (see fqdn()).
   * @param string $exportPath
   *   Absolute in-container path of the export directory
   *   (e.g. /var/www/html/html).
   */
  public static function nginxConf(string $fqdn, string $exportPath): string {
    $marker = self::MARKER;
    return <<<CONF
      # Static-export preview vhost — serves the Tome output beside the live
      # site so the export can be checked exactly as a host would serve it.
      # Regenerate the export with `drush dq:static`; this file is rewritten
      # by `drush dq:static --ddev-preview` while the marker below remains.
      {$marker}: remove this line to take ownership of the file.

      server {
          root {$exportPath};
          server_name {$fqdn};

          listen 80;
          listen 443 ssl;

          ssl_certificate /etc/ssl/certs/master.crt;
          ssl_certificate_key /etc/ssl/certs/master.key;

          include /etc/nginx/monitoring.conf;

          index index.html;
          sendfile off;
          error_log /dev/stdout info;
          access_log /var/log/nginx/access.log;

          # Pure static files — no PHP handler on this vhost. HTML answers
          # with no-cache so browsers revalidate on every load (a 304 when
          # unchanged) and a fresh `drush dq:static` shows immediately —
          # the asset types below stay cached.
          #
          # \$uri/index.html before \$uri/: the export's internal links are
          # slashless (Drupal path form, matching the canonical tags), and
          # \$uri/ alone would 301 them to the trailing-slash directory URL.
          # That hop breaks cross-document view transitions — a redirect
          # discards the old page's snapshot, turning the crossfade into a
          # white flash — so serve the directory index at the slashless URL
          # directly (200, no redirect).
          location / {
              absolute_redirect off;
              add_header Cache-Control "no-cache";
              try_files \$uri \$uri/index.html \$uri/ =404;
          }

          location ~* \\.(?:jpg|jpeg|gif|png|ico|svg|webp|woff2|css|js)\$ {
              expires 1M;
              access_log off;
              add_header Cache-Control "public";
          }

          location ~* /\\.(?!well-known\\/) {
              deny all;
          }

          include /etc/nginx/common.d/*.conf;
          include /mnt/ddev_config/nginx/*.conf;
      }

      CONF;
  }

  /**
   * Renders .ddev/config.static.yaml — a DDEV config override registering
   * the preview hostname without editing the user's config.yaml.
   *
   * DDEV merges config.*.yaml files over config.yaml, but LIST values
   * replace the base list rather than merging — if the project already sets
   * additional_hostnames in config.yaml, fold this entry in there and delete
   * the override instead.
   */
  public static function hostnamesOverride(string $hostname): string {
    $marker = self::MARKER;
    return <<<YAML
      {$marker}: written by `drush dq:static --ddev-preview`; remove this line to take ownership.
      # Registers the static-preview hostname (see nginx_full/static.conf).
      # Note: DDEV overrides REPLACE list values — if config.yaml declares its
      # own additional_hostnames, move this entry there and delete this file.
      additional_hostnames:
        - {$hostname}

      YAML;
  }

}
