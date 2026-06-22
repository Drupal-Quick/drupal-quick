<?php

namespace DrupalQuick\Drush\Commands;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Serialization\Yaml;
use Drush\Drush;
use Drush\Style\DrushStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ExecutableFinder;

/**
 * dq:static — exports the site to static HTML with Tome and writes a deploy
 * config for the configured target.
 *
 * Settings are seeded from the static: block in config.dq.yml on the first run
 * and persisted to Drupal config (drupalquick.static) so they survive
 * dq:cleanup, which deletes config.dq.yml.
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
      ->addOption('deploy', NULL, InputOption::VALUE_NONE, 'After exporting, deploy to the configured target (Netlify only for now).')
      ->addUsage('dq:static')
      ->addUsage('dq:static --base-url=https://example.com')
      ->addUsage('dq:static --deploy');
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

    // 3. Persist settings to Drupal config so re-exports work after cleanup.
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

    // 5. Run the static export.
    $this->io->writeln('🧊 [drupalquick] Generating static site with Tome...');
    $opts = ['yes' => TRUE];
    if ($uri) {
      $opts['uri'] = $uri;
    }
    Drush::drush($self, 'tome:static', [], $opts)->mustRun();

    // 6. Emit the deploy template for the chosen target.
    $this->emitDeployTemplate($target);

    $dir = $this->staticDirectory($self);
    $this->io->writeln('✅ [drupalquick] Static export complete.');
    $this->io->writeln("   Output: {$dir}/ (override via \$settings['tome_static_directory'] in settings.php).");

    // 7. Optionally deploy the export to the configured target.
    if ($input->getOption('deploy')) {
      return $this->deployStatic($target, $dir);
    }

    return self::SUCCESS;
  }

  /**
   * Resolves static-export settings (persisted config wins over config.dq.yml).
   */
  private function staticSettings($self): array {
    $process = Drush::drush($self, 'config:get', ['drupalquick.static', '--format=json']);
    $process->run();
    if ($process->isSuccessful()) {
      $persisted = Json::decode(trim((string) $process->getOutput())) ?: [];
      if (!empty($persisted['target']) || !empty($persisted['uri'])) {
        return $persisted;
      }
    }
    $configFile = getcwd() . '/config.dq.yml';
    if (file_exists($configFile)) {
      $config = Yaml::decode(file_get_contents($configFile)) ?: [];
      return $config['static'] ?? [];
    }
    return [];
  }

  /**
   * Resolves Tome's static output directory (default 'html').
   */
  private function staticDirectory($self): string {
    $process = Drush::drush($self, 'php:eval', ["echo \\Drupal\\Core\\Site\\Settings::get('tome_static_directory', 'html');"]);
    $process->run();
    $dir = trim((string) $process->getOutput());
    return $dir !== '' ? $dir : 'html';
  }

  /**
   * Writes the deploy configuration for the chosen target into the project.
   */
  private function emitDeployTemplate(string $target): void {
    $deployDir   = dirname(__DIR__, 3) . '/templates/deploy';
    $projectRoot = getcwd();

    if ($target === 'netlify') {
      $src = "{$deployDir}/netlify.toml";
      if (file_exists($src)) {
        copy($src, "{$projectRoot}/netlify.toml");
        $this->io->writeln('   Wrote netlify.toml');
      }
    }
    elseif ($target === 'github') {
      $src     = "{$deployDir}/github-pages.yml";
      $destDir = "{$projectRoot}/.github/workflows";
      if (file_exists($src)) {
        if (!is_dir($destDir)) {
          mkdir($destDir, 0755, TRUE);
        }
        copy($src, "{$destDir}/deploy-pages.yml");
        $this->io->writeln('   Wrote .github/workflows/deploy-pages.yml');
      }
    }
  }

  /**
   * Deploys the static export to the configured target (Netlify automated).
   */
  private function deployStatic(string $target, string $dir = 'html'): int {
    if ($target !== 'netlify') {
      $this->io->warning("--deploy currently automates the 'netlify' target only. For '{$target}', deploy via its own workflow (e.g. git push for GitHub Pages).");
      return self::SUCCESS;
    }

    // Prefer a globally installed CLI; fall back to npx (Node is available in
    // the build environment for the theme's Vite build). ExecutableFinder
    // locates the binary without invoking a shell.
    $netlify = (new ExecutableFinder())->find('netlify');
    $command = $netlify
      ? [$netlify, 'deploy', '--prod', "--dir={$dir}"]
      : ['npx', '--yes', 'netlify-cli', 'deploy', '--prod', "--dir={$dir}"];

    $this->io->writeln('🚀 [drupalquick] Deploying to Netlify...');
    $code = $this->runProcess($command);

    if ($code !== 0) {
      $this->io->error('Netlify deploy failed. The CLI must be authenticated inside the container: copy .ddev/.env.web.example to .ddev/.env.web, set NETLIFY_AUTH_TOKEN (and optionally NETLIFY_SITE_ID), then run `ddev restart` and retry. Prefer to keep secrets out of the container? Deploy from the host instead, where `netlify login` stored credentials: `netlify deploy --prod --dir=html`.');
      return $code;
    }

    $this->io->writeln('✅ [drupalquick] Netlify deploy complete.');
    return self::SUCCESS;
  }

}
