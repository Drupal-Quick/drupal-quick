<?php

namespace DrupalQuick\Drush\Commands;

use Drush\Drush;
use Drush\Style\DrushStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ExecutableFinder;

/**
 * dq:deploy — publishes the static export to the configured target.
 *
 * Split out from dq:static so each concern carries its own flags: dq:static
 * generates html/, dq:deploy ships it. Reads the target from the persisted
 * drupalquick.static config (seeded from config.dq.yml by dq:static), and is
 * loosely coupled to the build — it checks for the export and tells you to run
 * dq:static first if it's missing, rather than regenerating implicitly.
 */
#[AsCommand(
  name: 'dq:deploy',
  description: 'Deploys the static export to the configured target (Netlify automated).',
  aliases: ['dqdp'],
)]
final class DeployCommand extends Command {

  use DrupalQuickHelpers;

  protected function configure(): void {
    $this
      ->addOption('target', NULL, InputOption::VALUE_REQUIRED, 'Deploy target (netlify, github). Overrides the configured target.')
      ->addOption('prod', NULL, InputOption::VALUE_NONE, 'Deploy to production (Netlify). Default is a production deploy.')
      ->addUsage('dq:deploy')
      ->addUsage('dq:deploy --target=netlify');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $this->io = new DrushStyle($input, $output);
    $self = Drush::aliasManager()->getSelf();

    // 1. Resolve the target (flag overrides persisted/config setting).
    $settings = $this->staticSettings($self);
    $target   = $input->getOption('target') ?? ($settings['target'] ?? 'none');

    if ($target === 'none' || $target === '') {
      $this->io->error('No deploy target configured. Set static.target in config.dq.yml (netlify or github) or pass --target.');
      return self::FAILURE;
    }

    // 2. Require the static build (loose coupling — no implicit regeneration).
    $dir  = $this->staticDirectory($self);
    $path = getcwd() . '/' . $dir;
    if (!is_dir($path)) {
      $this->io->error("No static build found at {$dir}/. Run `drush dq:static` first.");
      return self::FAILURE;
    }

    // 3. Emit the deploy configuration for the target (idempotent).
    $this->emitDeployTemplate($target);

    // 4. Deploy.
    return $this->deployStatic($target, $dir);
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
      $this->io->warning("dq:deploy currently automates the 'netlify' target only. For '{$target}', deploy via its own workflow (e.g. git push for GitHub Pages — the workflow was written to .github/workflows/).");
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
