<?php

namespace DrupalQuick\Drush\Commands;

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
      ->addUsage('dq:static')
      ->addUsage('dq:static --base-url=https://example.com');
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

    // 5. Run the static export.
    $this->io->writeln('🧊 [drupalquick] Generating static site with Tome...');
    $opts = ['yes' => TRUE];
    if ($uri) {
      $opts['uri'] = $uri;
    }
    Drush::drush($self, 'tome:static', [], $opts)->mustRun();

    // @todo Investigate an optional post-generation optimization pass over the
    //   static output (the html/ dir): HTML/CSS/JS minification and image
    //   optimization — potentially by running Vite plugins (or a dedicated
    //   optimizer) across the exported files, behind a flag so it stays opt-in.

    $dir = $this->staticDirectory($self);
    $this->io->writeln('✅ [drupalquick] Static export complete.');
    $this->io->writeln("   Output: {$dir}/ (override via \$settings['tome_static_directory'] in settings.php).");
    $this->io->writeln("   Deploy it with `drush dq:deploy`.");

    return self::SUCCESS;
  }

}
