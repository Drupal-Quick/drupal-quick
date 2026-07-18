<?php

namespace DrupalQuick\Drush\Commands;

use DrupalQuick\Ddev\StaticPreview;
use DrupalQuick\Static\ExportHostRewrite;
use Drush\Drush;
use Drush\Style\DrushStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * dq:static — exports the site to static HTML with Tome.
 *
 * Generation only; deploying the result is dq:deploy. Settings are seeded from
 * the static: block in config.dq.yml on the first run and persisted to Drupal
 * config (drupalquick.static) so they survive dq:cleanup (which deletes
 * config.dq.yml) and are available to dq:deploy afterwards.
 */
#[AsCommand(
  name: 'dq:static',
  description: 'Generates a static HTML export of the site with Tome.',
  aliases: ['dqst'],
)]
final class StaticExportCommand extends Command {

  use DrupalQuickHelpers;

  protected function configure(): void {
    $this
      ->addOption('base-url', NULL, InputOption::VALUE_REQUIRED, 'The production base URL for absolute links, passed to Tome as --uri (overrides config).')
      ->addOption('ddev-preview', NULL, InputOption::VALUE_NEGATABLE, 'Also provision a DDEV preview vhost (https://static.<project>.ddev.site) serving the export beside the live site. Defaults to on when the command runs inside a DDEV web container; --no-ddev-preview opts out. Run `ddev restart` once after the first provisioning.', NULL)
      ->addUsage('dq:static')
      ->addUsage('dq:static --base-url=https://example.com')
      ->addUsage('dq:static --ddev-preview')
      ->addUsage('dq:static --no-ddev-preview');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $this->io = new DrushStyle($input, $output);
    $self = Drush::aliasManager()->getSelf();

    // 1. Resolve settings (persisted config wins; fall back to config.dq.yml).
    $settings = $this->staticSettings($self);
    $target   = $settings['target'] ?? 'none';
    $uri      = $input->getOption('base-url') ?? ($settings['uri'] ?? NULL);

    // 2. Ensure Tome static is installed and enabled.
    $this->io->writeln('📦 [drupalquick] Ensuring Tome static is available...');
    $hasTome = trim((string) Drush::drush($self, 'php:eval', ["echo \\Drupal::moduleHandler()->moduleExists('tome_static') ? '1' : '0';"])->mustRun()->getOutput()) === '1';
    if (!$hasTome) {
      $this->io->writeln('   Installing drupal/tome via Composer...');
      $code = $this->runProcess(['composer', 'require', 'drupal/tome']);
      if ($code !== 0) {
        $this->io->error('composer require drupal/tome failed.');
        return self::FAILURE;
      }
      Drush::drush($self, 'pm:install', ['tome_static'], ['yes' => TRUE])->mustRun();
    }

    // 3. Persist settings to Drupal config so dq:deploy and re-exports work
    //    after cleanup (which deletes config.dq.yml).
    Drush::drush($self, 'config:set', ['drupalquick.static', 'target', $target], ['yes' => TRUE])->mustRun();
    if ($uri) {
      Drush::drush($self, 'config:set', ['drupalquick.static', 'uri', $uri], ['yes' => TRUE])->mustRun();
    }

    // 4. Preflight the active theme: must be built, and the Vite dev marker must
    //    be absent or the export would capture localhost dev-server tags.
    $theme    = trim((string) Drush::drush($self, 'config:get', ['system.theme', 'default', '--format=string'])->mustRun()->getOutput());
    $themeDir = $this->drupalRoot() . "/themes/custom/{$theme}";
    if (is_dir($themeDir)) {
      if (file_exists("{$themeDir}/.vite-dev")) {
        $this->io->error("The Vite dev server appears to be running ({$theme}/.vite-dev exists). Stop it and run `npm run build` before exporting.");
        return self::FAILURE;
      }
      if (!file_exists("{$themeDir}/dist/main.css")) {
        $this->io->warning("Theme '{$theme}' has no built assets (dist/main.css missing). Run `npm install && npm run build` in {$themeDir} for styled output.");
      }
    }

    // 4.5. If Twig development mode is on (dq:scaffold enables it for local
    // theme iteration), turn it off for the export: with twig debug on, Drupal
    // bakes `<!-- THEME HOOK -->` / file-name-suggestion comments into every
    // rendered page, which would leak template paths into the static HTML.
    // Toggling it off restores the production render; it's restored afterward
    // so the developer's session continues. It's a DB key-value, so reading and
    // flipping it is cheap and leaves no file trace.
    $probe = Drush::drush($self, 'php:eval', ["echo \\Drupal::keyValue('development_settings')->get('twig_debug') ? '1' : '';"]);
    $probe->run();
    $wasThemeDev = $probe->isSuccessful() && trim((string) $probe->getOutput()) === '1';
    if ($wasThemeDev) {
      $this->io->writeln('🧑‍🎨 [drupalquick] Twig development mode is on — disabling it for a clean export (restored afterward).');
      Drush::drush($self, 'theme:dev', ['off'], ['yes' => TRUE])->mustRun();
    }

    // 5. Clear Tome's static cache before every export. Tome's cache is
    // content-keyed, not target-URI-keyed: a page rendered once under the
    // site's live authoring host (e.g. a DDEV domain) is served from that
    // cache as-is on later runs even after static.uri is set or changed,
    // since nothing about the page's own content changed — leaking the
    // authoring host into canonical links, RSS, and JSON-LD indefinitely.
    // dq:static exists to represent the site's current state, so a full
    // fresh render on every run is the correct default over a faster but
    // possibly-stale incremental one (site sizes Quick targets make this
    // cheap; path-count=1 below already trades some speed for correctness).
    Drush::drush($self, 'php:eval', ["\\Drupal::cache('tome_static')->deleteAll();"])->mustRun();

    // 6. Run the static export. One path per worker process: Drupal
    // memoizes per-request state in long-lived services (menu.active_trail
    // caches its route lookup for the life of the process), so Tome's
    // default of several paths per process bakes the first page's active
    // menu trail into every later page in the chunk. Fresh process per
    // path keeps the server-rendered markup truthful; parallelism across
    // processes (--process-count) still applies.
    $this->io->writeln('🧊 [drupalquick] Generating static site with Tome...');
    $opts = ['yes' => TRUE, 'path-count' => 1];
    if ($uri) {
      $opts['uri'] = $uri;
    }
    Drush::drush($self, 'tome:static', [], $opts)->mustRun();

    $dir = $this->staticDirectory($self);

    // 7. Belt-and-suspenders: rewrite any stray reference to the live
    // authoring host into the configured URI across the exported files.
    // Guards against the wrong host leaking through by any mechanism, not
    // just the cache behavior above — cheap for a static site this size.
    if ($uri) {
      $this->rewriteExportHost($dir, $self, $uri);
    }

    // 8. Emit a sibling <path>.html beside every <path>/index.html. The
    // export's internal links are slashless (Drupal path form, matching the
    // canonical tags), but static hosts 301 a slashless URL to its
    // trailing-slash directory form — and that redirect discards the old
    // page's view-transition snapshot, turning the page crossfade into a
    // white flash. Netlify and GitHub Pages both resolve extensionless URLs
    // to .html files *before* their directory handling, so the sibling makes
    // slashless URLs serve directly (200, no redirect). The DDEV preview's
    // nginx handles the same via try_files. Runs after the host rewrite so
    // siblings copy the corrected markup.
    $this->emitSlashlessSiblings($dir);

    // Restore Twig development mode if the export turned it off, so the
    // developer's local session picks up where it left off.
    if ($wasThemeDev) {
      Drush::drush($self, 'theme:dev', ['on'], ['yes' => TRUE])->run();
      $this->io->writeln('🧑‍🎨 [drupalquick] Restored Twig development mode (drush theme:dev off to disable).');
    }

    // @todo Before launch: emit a _redirects file into the export from a
    //   static.redirects map in config.dq.yml (persisted to drupalquick.static
    //   like target/uri), written HERE — after tome:static, which regenerates
    //   the export dir — alongside the host-rewrite pass above. Netlify-target
    //   scoped: GitHub Pages ignores _redirects (would need meta-refresh
    //   stubs), and the DDEV preview's nginx won't honor it either (document
    //   that preview/production difference). Later, optionally merge entries
    //   harvested from the redirect contrib module when it's installed (the
    //   good idea inside tome_netlify) for editor-made renames on live sites.
    //   Post-launch discipline this enables: no URL changes without a redirect.

    // @todo Investigate an optional post-generation optimization pass over the
    //   static output (the html/ dir): HTML/CSS/JS minification and image
    //   optimization — potentially by running Vite plugins (or a dedicated
    //   optimizer) across the exported files, behind a flag so it stays opt-in.

    $this->io->writeln('✅ [drupalquick] Static export complete.');
    $this->io->writeln("   Output: {$dir}/ (override via \$settings['tome_static_directory'] in settings.php).");
    $this->io->writeln("   Deploy it with `drush dq:deploy`.");

    // 6. Provision the DDEV preview vhost for the export. With no explicit
    // flag this is automatic inside a DDEV web container (IS_DDEV_PROJECT is
    // set by DDEV) — there the vhost is free and marker-guarded, so the only
    // cost is two managed files. Auto mode degrades to a note when the
    // project layout doesn't support it; only the explicit --ddev-preview
    // treats that as a failure.
    $preview = $input->getOption('ddev-preview');
    if ($preview === TRUE) {
      return $this->provisionDdevPreview($dir);
    }
    if ($preview === NULL && getenv('IS_DDEV_PROJECT') === 'true') {
      $this->io->writeln('🌐 [drupalquick] DDEV detected — provisioning the static preview vhost (skip with --no-ddev-preview).');
      if ($this->provisionDdevPreview($dir) !== self::SUCCESS) {
        $this->io->writeln('   Preview vhost skipped (see above); the export itself succeeded.');
      }
    }

    return self::SUCCESS;
  }

  /**
   * Rewrites the site's live authoring host to $targetUri across every
   * text-like file in the export directory (see ExportHostRewrite).
   */
  private function rewriteExportHost(string $exportDir, $self, string $targetUri): void {
    $process = Drush::drush($self, 'php:eval', ["echo \\Drupal::request()->getSchemeAndHttpHost();"]);
    $process->run();
    $liveHost = trim((string) $process->getOutput());
    if ($liveHost === '' || rtrim($liveHost, '/') === rtrim($targetUri, '/')) {
      return;
    }

    $path = str_starts_with($exportDir, '/') ? $exportDir : getcwd() . '/' . $exportDir;
    if (!is_dir($path)) {
      return;
    }

    $fixed = 0;
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      if (!$file->isFile() || !ExportHostRewrite::isRewritable($file->getPathname())) {
        continue;
      }
      $contents = file_get_contents($file->getPathname());
      if ($contents === FALSE) {
        continue;
      }
      $rewritten = ExportHostRewrite::rewrite($contents, $liveHost, $targetUri);
      if ($rewritten !== $contents) {
        file_put_contents($file->getPathname(), $rewritten);
        $fixed++;
      }
    }

    if ($fixed > 0) {
      $this->io->writeln("🔧 [drupalquick] Rewrote {$liveHost} → {$targetUri} in {$fixed} exported file(s).");
    }
  }

  /**
   * Copies every <dir>/index.html in the export to a sibling <dir>.html so
   * slashless URLs resolve as files on static hosts (no 301 — see step 8).
   * A real page that already exports as <dir>.html is never overwritten.
   */
  private function emitSlashlessSiblings(string $exportDir): void {
    $root = str_starts_with($exportDir, '/') ? $exportDir : getcwd() . '/' . $exportDir;
    if (!is_dir($root)) {
      return;
    }
    $emitted = 0;
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      if (!$file->isFile() || $file->getFilename() !== 'index.html') {
        continue;
      }
      $dir = dirname($file->getPathname());
      // The export root's own index.html is "/" — no sibling to emit.
      if ($dir === $root) {
        continue;
      }
      $sibling = $dir . '.html';
      if (file_exists($sibling)) {
        continue;
      }
      copy($file->getPathname(), $sibling);
      $emitted++;
    }
    if ($emitted > 0) {
      $this->io->writeln("🔗 [drupalquick] Emitted {$emitted} slashless .html sibling(s) so static hosts serve extensionless URLs without redirecting.");
    }
  }

  /**
   * Writes the DDEV preview vhost config: an nginx server block rooted at
   * the export directory plus a config.*.yaml override registering the
   * static.<project> hostname (see StaticPreview for the rendered content
   * and ownership-marker semantics). Writing config is all that can happen
   * from inside the web container — the user runs `ddev restart` once.
   */
  private function provisionDdevPreview(string $exportDir): int {
    $projectRoot = dirname($this->drupalRoot());
    $ddevConfig  = "{$projectRoot}/.ddev/config.yaml";
    if (!file_exists($ddevConfig)) {
      $this->io->error('--ddev-preview needs a DDEV project (.ddev/config.yaml not found).');
      return self::FAILURE;
    }

    $configYaml = (string) file_get_contents($ddevConfig);
    $hostname   = StaticPreview::hostname($configYaml);
    $fqdn       = StaticPreview::fqdn($configYaml);
    if ($hostname === NULL) {
      $this->io->error("Could not read the project name from {$ddevConfig}.");
      return self::FAILURE;
    }

    // Absolute in-container path for the nginx root (the export directory
    // setting is normally relative to the project root).
    $exportPath = str_starts_with($exportDir, '/') ? $exportDir : "{$projectRoot}/{$exportDir}";

    $files = [
      "{$projectRoot}/.ddev/nginx_full/static.conf" => StaticPreview::nginxConf($fqdn, $exportPath),
      "{$projectRoot}/.ddev/config.static.yaml" => StaticPreview::hostnamesOverride($hostname),
    ];
    $wrote = FALSE;
    foreach ($files as $path => $contents) {
      $existing = file_exists($path) ? (string) file_get_contents($path) : NULL;
      if (!StaticPreview::isManaged($existing)) {
        $this->io->writeln('   Left ' . basename($path) . ' untouched (marker removed — user-owned).');
        continue;
      }
      if ($existing === $contents) {
        continue;
      }
      if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, TRUE);
      }
      file_put_contents($path, $contents);
      $this->io->writeln("   Wrote {$path}");
      $wrote = TRUE;
    }

    $this->io->writeln("🌐 Static preview vhost: https://{$fqdn}");
    if ($wrote) {
      $this->io->writeln('   Run `ddev restart` once to activate it.');
    }
    return self::SUCCESS;
  }

}
