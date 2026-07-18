<?php

namespace DrupalQuick\Drush\Commands;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Serialization\Yaml;
use DrupalQuick\Config\PresetDiscovery;
use DrupalQuick\Config\RecipeEntry;
use Drush\Drush;
use Drush\Style\DrushStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * dq:scaffold — builds a Drupal site from config.dq.yml.
 *
 *   1. Installs Drupal with the minimal profile.
 *   2. Generates a custom theme from the dq_starterkit theme package, applying
 *      the chosen skin and theme_design tokens.
 *   3. Applies each recipe in order, injecting recipe theme assets.
 *   4. Runs post-recipe config overrides.
 *   5. Compiles theme assets via npm/Vite.
 *
 * Path context: getcwd() is the directory Drush was invoked from — normally the
 * Composer project root (one level above the docroot), where config.dq.yml
 * lives. The web root comes from drupalRoot(); package-internal assets are
 * addressed with absolute paths via __DIR__.
 */
#[AsCommand(
  name: 'dq:scaffold',
  description: 'Scaffolds a Drupal site using config.dq.yml.',
  aliases: ['dqs'],
)]
final class ScaffoldCommand extends Command {

  use DrupalQuickHelpers;

  protected function configure(): void {
    $this
      ->addOption('interactive', NULL, InputOption::VALUE_NONE, 'Prompt the user to fill out or override config values interactively.')
      ->addOption('force', NULL, InputOption::VALUE_NONE, 'Scaffold even when Drupal is already installed. This reinstalls the site and DESTROYS the existing database.')
      ->addOption('theme-dev', NULL, InputOption::VALUE_NEGATABLE, 'Enable Twig development mode (twig debug + auto-reload, caches off) via `drush theme:dev` after the build, for live theme iteration. Defaults to on inside a DDEV web container; --no-theme-dev opts out. Reverse any time with `drush theme:dev off`.', NULL)
      ->addUsage('dq:scaffold')
      ->addUsage('dq:scaffold --interactive')
      ->addUsage('dq:scaffold --no-theme-dev');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $this->io = new DrushStyle($input, $output);
    if (!$this->guardDdevEnvironment()) {
      return self::FAILURE;
    }

    $configFile = getcwd() . '/config.dq.yml';
    if (!file_exists($configFile)) {
      $this->io->error('config.dq.yml not found. Run "composer exec dq-init" first.');
      return self::FAILURE;
    }

    $this->io->writeln('⚡ [drupalquick] Processing configuration...');
    try {
      $config = Yaml::decode(file_get_contents($configFile));
    }
    catch (\Exception $e) {
      $this->io->error('Error parsing config.dq.yml: ' . $e->getMessage());
      return self::FAILURE;
    }

    $registry = $this->registry();

    // Refuse to scaffold over a live site: the site:install in runBuild()
    // drops and recreates the database, so an accidental re-run (e.g. while
    // rehearsing the workflow) would destroy real content. The probe failing
    // means no bootable site exists — a fresh project — which is exactly when
    // scaffolding should proceed.
    if (!$input->getOption('force')) {
      $probe = Drush::drush(Drush::aliasManager()->getSelf(), 'php:eval', ["echo \\Drupal::state()->get('install_task') === 'done' ? 'installed' : '';"]);
      $probe->run();
      if ($probe->isSuccessful() && str_contains((string) $probe->getOutput(), 'installed')) {
        $this->io->error('Drupal is already installed here. Re-running dq:scaffold would reinstall it and destroy the existing database. Pass --force to rebuild deliberately.');
        return self::FAILURE;
      }
    }

    // --- Interactive prompts ---
    if ($input->getOption('interactive')) {
      $this->io->writeln("\n💬 [drupalquick] Entering interactive configuration mode...");

      $config['site']['name'] = $this->io->ask(
        'What is the name of this Drupal site?',
        $config['site']['name'] ?? 'Drupal Site'
      );

      // Discover presets from the installed starterkit (package.json "dq"),
      // falling back to scanning its presets/ directory.
      $starterkitDir    = $this->drupalRoot() . '/themes/contrib/dq_starterkit';
      [$availablePresets, $defaultPreset] = PresetDiscovery::discover($starterkitDir);

      $config['theme']['preset'] = $this->io->choice(
        'Which design preset would you like to apply?',
        $availablePresets,
        $config['theme']['preset'] ?? $defaultPreset
      );

      $this->io->writeln("✅ Configuration updated for this session.\n");
    }

    // Resolve theme-dev: explicit flag wins; otherwise default on inside a
    // DDEV web container (the local-iteration context), like dq:static's
    // --ddev-preview. Applied at the end of a successful build.
    $themeDevOpt = $input->getOption('theme-dev');
    $enableThemeDev = $themeDevOpt ?? (getenv('IS_DDEV_PROJECT') === 'true');

    // Run the build phases inside one try/catch: every Drush call below uses
    // mustRun(), so any failure aborts with a clear "site may be partially
    // built" message instead of a raw stack trace.
    try {
      return $this->runBuild($config, $registry, $enableThemeDev);
    }
    catch (\Throwable $e) {
      $this->io->error('Scaffold failed: ' . $e->getMessage());
      $this->io->error('The site may be partially built — review the error above before re-running `drush dq:scaffold`.');
      return self::FAILURE;
    }
  }

  /**
   * Runs the build phases: install → theme → recipes → config → assets.
   */
  private function runBuild(array $config, array $registry, bool $enableThemeDev = FALSE): int {
    $siteName    = $config['site']['name'] ?? 'Drupal Site';
    $accountName = $config['site']['admin_user'] ?? 'admin';
    // Secure default: when no admin_pass is configured, generate a strong one
    // and show it once, rather than installing with a weak, well-known
    // credential. A password set explicitly in config.dq.yml still wins.
    $accountPass   = (string) ($config['site']['admin_pass'] ?? '');
    $generatedPass = ($accountPass === '');
    if ($generatedPass) {
      $accountPass = $this->generatePassword();
    }
    $themeName   = $config['theme']['machine_name'] ?? NULL;
    $themeTitle  = $config['theme']['title'] ?? 'Custom Theme';
    $themePreset = $config['theme']['preset'] ?? NULL;
    $themeLayout = $config['theme']['layout'] ?? NULL;
    $themeBuild  = $config['theme']['build'] ?? TRUE;
    $recipes     = $config['recipes'] ?? [];
    $parameters  = $config['parameters'] ?? [];

    // 1. Install Drupal with the minimal profile (recipes layer in the rest).
    $this->io->writeln('⚙️  Installing Drupal...');
    Drush::drush(Drush::aliasManager()->getSelf(), 'site:install', ['minimal'], [
      'site-name'    => $siteName,
      'account-name' => $accountName,
      'account-pass' => $accountPass,
      'yes'          => TRUE,
    ])->mustRun();

    if ($generatedPass) {
      $this->io->writeln("   🔑 Generated admin password for '{$accountName}': {$accountPass}");
      $this->io->writeln('   Save it now — drupalquick does not store it anywhere.');
    }

    // 2. Generate the theme from the starterkit. dq_starterkit is installed as a
    // drupal-theme package at themes/contrib/dq_starterkit, so Drupal discovers
    // it natively and generate-theme can point straight at it — no staging.
    if ($themeName) {
      $this->io->writeln("🎨 Generating theme '{$themeName}' from starterkit...");

      $starterkitId  = 'dq_starterkit';
      $drupalRoot    = $this->drupalRoot();
      $themeDir      = "{$drupalRoot}/themes/custom/{$themeName}";
      $starterkitDir = "{$drupalRoot}/themes/contrib/{$starterkitId}";

      if (!is_dir($starterkitDir)) {
        $this->io->error("Starterkit theme not found at themes/contrib/{$starterkitId}. Require it with: composer require drupal-quick/dq_starterkit");
        return self::FAILURE;
      }

      // Prefer core's consolidated CLI (vendor/bin/dr, 11.4+); fall back to
      // the legacy script on older cores (see drupalCoreCli()).
      $drupalCli = $this->drupalCoreCli() ?? ['php', "{$drupalRoot}/core/scripts/drupal"];

      $genCode = $this->runProcess([
        ...$drupalCli, 'generate-theme',
        $themeName,
        "--name={$themeTitle}",
        '--description=A custom Drupal theme built with Tailwind CSS and Vite.',
        '--path=themes/custom',
        "--starterkit={$starterkitId}",
      ]);

      if ($genCode !== 0 || !is_dir($themeDir)) {
        $this->io->error("Theme generation failed (generate-theme exit code {$genCode}).");
        return $genCode ?: self::FAILURE;
      }

      // Strip the generator line so the finished theme carries no reference back
      // to the starterkit.
      $infoFile = "{$themeDir}/{$themeName}.info.yml";
      if (file_exists($infoFile)) {
        $info = preg_replace('/^generator:.*\n?/m', '', file_get_contents($infoFile));
        file_put_contents($infoFile, $info);
      }

      // Enable the theme now so it exists while recipes are applied; the default
      // is set after recipes (step 3.5) so recipe-set defaults do not clobber it.
      Drush::drush(Drush::aliasManager()->getSelf(), 'theme:enable', [$themeName], ['yes' => TRUE])->mustRun();

      // Bake the chosen page-shell arrangement. Layout is a scaffold-time
      // choice, not a runtime setting: the starterkit ships the default shell
      // (templates/includes/page-shell.html.twig — the sidebar arrangement,
      // embedded by both page templates) plus one file per alternative
      // (page-shell--<layout>.html.twig). The chosen variant replaces the
      // shell, the unchosen ones are removed, and from then on the shell is
      // ordinary Twig the user edits directly. Runs before the theme build, so
      // only the chosen arrangement's classes are compiled.
      $shellDir = "{$themeDir}/templates/includes";
      if ($themeLayout && $themeLayout !== 'sidebar') {
        $variant = "{$shellDir}/page-shell--{$themeLayout}.html.twig";
        if (file_exists($variant)) {
          $this->io->writeln("🧭 Baking the '{$themeLayout}' page shell...");
          copy($variant, "{$shellDir}/page-shell.html.twig");
        }
        else {
          $this->io->warning("Unknown layout '{$themeLayout}' — no page-shell--{$themeLayout}.html.twig in the starterkit. Keeping the default shell.");
        }
      }
      foreach (glob("{$shellDir}/page-shell--*.html.twig") ?: [] as $variantFile) {
        unlink($variantFile);
      }

      // Token application is deferred to the preset step (`npm run preset` in
      // step 5) — the single source of truth, which also serves re-skinning
      // after scaffold. Here we only translate config.dq.yml's theme_design into
      // a persisted overrides file that the preset script layers on top of the
      // chosen preset. (Tailwind v4 processes @theme at build time, so tokens
      // can't be applied until the build runs anyway.)
      $design = $parameters['theme_design'] ?? [];
      if ($design) {
        $lines = '';
        foreach ($design as $key => $value) {
          $lines .= '  ' . $this->designTokenName($key) . ": {$value};\n";
        }
        $presetsDir = "{$themeDir}/presets";
        if (!is_dir($presetsDir)) {
          mkdir($presetsDir, 0755, TRUE);
        }
        file_put_contents(
          "{$presetsDir}/overrides.css",
          "/* theme_design overrides from config.dq.yml — layered over the preset. */\n@theme static {\n{$lines}}\n"
        );
        $this->io->writeln('🖌️  Wrote theme_design overrides (presets/overrides.css).');
      }
    }

    // 2.5. Assemble recipe submodules into the umbrella module. Each recipe may
    // ship a module/ directory carrying its behaviour (preprocess + JSON-LD) as
    // native OOP #[Hook] methods. Because every submodule is its own extension,
    // their hook implementations stack with the theme and with each other — no
    // shared dispatcher is needed. Done before applying recipes so a recipe's
    // `install:` can enable its own module.
    if (!empty($recipes)) {
      $umbrellaDir = $this->ensureUmbrellaModule();
      $assembled = [];
      foreach ($recipes as $recipe) {
        [$ref] = RecipeEntry::normalize($recipe);
        $path = $this->resolvePath($ref, $registry);
        if ($path !== $ref && ($name = $this->assembleRecipeModule($path, $umbrellaDir))) {
          $assembled[] = $name;
        }
      }
      if ($assembled) {
        // Rebuild so the newly placed modules are discoverable before the
        // recipes' install steps enable them.
        Drush::drush(Drush::aliasManager()->getSelf(), 'cache:rebuild')->mustRun();
        $this->io->writeln('   Assembled recipe modules: ' . implode(', ', $assembled));
      }
    }

    // 3. Apply recipes in declared order, injecting theme assets as we go.
    if (!empty($recipes)) {
      $this->io->writeln('📦 Applying Drupal recipes...');
      foreach ($recipes as $recipe) {
        [$ref, $options] = RecipeEntry::normalize($recipe);
        $path = $this->resolvePath($ref, $registry);
        // Managed recipes (a registry key or inline spec) resolve to a recipes/
        // path; core/contrib path strings pass through unchanged.
        $managed = ($path !== $ref);
        if ($managed) {
          $label = is_array($ref) ? ($ref['package'] ?? 'recipe') : $ref;
          $this->io->writeln("   Resolved '{$label}' → {$path}");
        }

        // The entry's options map to native recipe inputs: each becomes
        // --input=<recipe-dir>.<name>=<value> (core prefixes input names with
        // the recipe directory's basename). Unset options fall back to the
        // recipe.yml input defaults.
        $inputFlags = [];
        foreach ($options as $key => $value) {
          if (!is_scalar($value)) {
            $this->io->warning("Skipping non-scalar option '{$key}' for recipe '{$path}'.");
            continue;
          }
          $value        = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
          $inputFlags[] = '--input=' . basename($path) . ".{$key}={$value}";
        }

        // Core 11.4 moved recipe application to `dr recipe:apply` (the drush
        // `recipe` command is gone); older cores still expose it via drush.
        // The drush path passes --input flags as extra args because the
        // site-process option serializer can't repeat an option; Symfony
        // parses them as options regardless of position.
        if ($dr = $this->drupalCoreCli()) {
          $code = $this->runProcess([...$dr, 'recipe:apply', $path, ...$inputFlags, '--no-interaction']);
          if ($code !== 0) {
            $this->io->error("Applying recipe '{$path}' failed (exit code {$code}).");
            return $code;
          }
        }
        else {
          Drush::drush(Drush::aliasManager()->getSelf(), 'recipe', [$path, ...$inputFlags], ['yes' => TRUE])->mustRun();
        }

        // Inject theme assets when the recipe ships a theme-assets/ directory
        // (copyThemeAssets no-ops when it doesn't — no registry flag needed).
        if ($themeName && $managed) {
          $this->copyThemeAssets($path, $themeName);
        }
      }
    }

    // 3.4. Recipes install modules in separate processes; rebuild so every
    // subsequent drush call boots a container that knows the new extensions
    // (without this, e.g. config:set can hit "entity type does not exist").
    if (!empty($recipes)) {
      Drush::drush(Drush::aliasManager()->getSelf(), 'cache:rebuild')->mustRun();
    }

    // 3.5. Set the generated theme as the site default (after recipes).
    if ($themeName) {
      $this->io->writeln("🎨 Setting '{$themeName}' as the default theme...");
      Drush::drush(Drush::aliasManager()->getSelf(), 'config:set', ['system.theme', 'default', $themeName], ['yes' => TRUE])->mustRun();
    }

    // 3.7. Compose the homepage from recipe-advertised blocks. Each
    // homepage.blocks entry is "<recipe-key>/<block-key>", resolved through the
    // registry's blocks metadata (recipes advertise placeable blocks in their
    // composer.json extra.dq.recipe.blocks — capabilities ship with the recipe;
    // *placement* is selected here). Blocks land in the theme's content region,
    // restricted to <front>, ordered by the list. The result is ordinary block
    // config — rearrange or remove later at /admin/structure/block. When
    // homepage.blocks is absent, whatever front page the recipes set (e.g. the
    // blog's /writing) stands.
    $homepageBlocks = $config['homepage']['blocks'] ?? [];
    if ($themeName && $homepageBlocks) {
      $this->io->writeln('🏠 Composing the homepage from recipe blocks...');
      // Block placement needs the block module (standard ships it; minimal
      // alone does not). pm:install is a no-op when it is already enabled.
      Drush::drush(Drush::aliasManager()->getSelf(), 'pm:install', ['block'], ['yes' => TRUE])->mustRun();

      $weight = -10;
      foreach ($homepageBlocks as $entry) {
        [$recipeKey, $blockKey] = array_pad(explode('/', (string) $entry, 2), 2, '');
        $blockMeta = $registry[$recipeKey]['blocks'][$blockKey] ?? NULL;
        if (!$blockMeta || empty($blockMeta['plugin'])) {
          $this->io->warning("Unknown homepage block '{$entry}' — recipe '{$recipeKey}' does not advertise '{$blockKey}'. Skipped.");
          continue;
        }
        $values = [
          'id'         => preg_replace('/[^a-z0-9_]+/', '_', strtolower("{$themeName}_dq_{$recipeKey}_{$blockKey}")),
          'theme'      => $themeName,
          'region'     => 'content',
          'plugin'     => $blockMeta['plugin'],
          'weight'     => $weight++,
          'settings'   => [
            'label'         => (string) ($blockMeta['label'] ?? $entry),
            'label_display' => '0',
          ],
          'visibility' => [
            'request_path' => [
              'id'     => 'request_path',
              'negate' => FALSE,
              'pages'  => '<front>',
            ],
          ],
        ];
        // Created via php:eval — block config entities cannot be built with
        // plain config:set, and the scaffold already shells out per step.
        $code = sprintf('\Drupal\block\Entity\Block::create(%s)->save();', var_export($values, TRUE));
        Drush::drush(Drush::aliasManager()->getSelf(), 'php:eval', [$code])->mustRun();
        $this->io->writeln("   Placed {$entry} → {$values['id']}");
      }

      // The composed homepage lives at /home — a dedicated, always-empty view
      // page created for the purpose (the chosen blocks render there via
      // <front> visibility). A view rather than a custom route keeps this
      // config-only: after scaffold the admin can repoint system.site
      // page.front, or edit/delete the view like any other. Its bundle filter
      // matches nothing, so the view contributes no rows of its own.
      Drush::drush(Drush::aliasManager()->getSelf(), 'pm:install', ['views'], ['yes' => TRUE])->mustRun();
      $home = <<<'PHP'
if (!\Drupal\views\Entity\View::load('dq_home')) {
  \Drupal\views\Entity\View::create([
    'id' => 'dq_home',
    'label' => 'Home',
    'description' => 'Empty page at /home hosting the composed homepage blocks (created by dq:scaffold).',
    'base_table' => 'node_field_data',
    'base_field' => 'nid',
    'display' => [
      'default' => [
        'id' => 'default',
        'display_plugin' => 'default',
        'display_title' => 'Default',
        'position' => 0,
        'display_options' => [
          'access' => ['type' => 'perm', 'options' => ['perm' => 'access content']],
          'cache' => ['type' => 'tag', 'options' => []],
          'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
          'filters' => [
            'type' => [
              'id' => 'type', 'table' => 'node_field_data', 'field' => 'type',
              'entity_type' => 'node', 'entity_field' => 'type', 'plugin_id' => 'bundle',
              'value' => ['dq_home_none' => 'dq_home_none'],
            ],
          ],
          'title' => '',
        ],
      ],
      'page_1' => [
        'id' => 'page_1',
        'display_plugin' => 'page',
        'display_title' => 'Page',
        'position' => 1,
        'display_options' => ['path' => 'home'],
      ],
    ],
  ])->save();
}
PHP;
      Drush::drush(Drush::aliasManager()->getSelf(), 'php:eval', [$home])->mustRun();
      Drush::drush(Drush::aliasManager()->getSelf(), 'config:set', ['system.site', 'page.front', '/home'], ['yes' => TRUE])->mustRun();
      // Router rebuild so the new /home path routes (the scaffold's final
      // cache rebuild would also cover it, but blocks placed above should be
      // verifiable immediately).
      Drush::drush(Drush::aliasManager()->getSelf(), 'cache:rebuild')->mustRun();
    }

    // 4. Post-recipe config overrides.
    if (!empty($parameters['recipe_config'])) {
      $this->io->writeln('⚙️  Applying recipe_config parameter overrides...');
      foreach ($parameters['recipe_config'] as $configName => $settings) {
        if (is_array($settings)) {
          foreach ($settings as $key => $value) {
            if (!is_scalar($value)) {
              $this->io->warning("Skipping non-scalar recipe_config value for {$configName}:{$key} — config:set takes scalar values only.");
              continue;
            }
            Drush::drush(Drush::aliasManager()->getSelf(), 'config:set', [$configName, $key, $value], ['yes' => TRUE])->mustRun();
          }
        }
      }
    }

    // 5. Apply the design preset and build the theme. `npm run preset` writes the
    // chosen preset's tokens into main.css (layering presets/overrides.css),
    // fetches any preset fonts on demand, and rebuilds — the same path users
    // re-run later to change presets. `build: false` stages the tokens without
    // compiling.
    //
    // @todo generate-theme copies the whole presets/ tree into the theme, so all
    //   presets (not just the chosen one) ship in the generated site — retained
    //   for now because they make re-skinning and testing easy, and fonts are no
    //   longer bundled so an unused preset costs only a little CSS + JSON. Revisit
    //   pruning the unselected presets at scaffold once the catalogue grows.
    if ($themeName) {
      $themeDir = $this->drupalRoot() . "/themes/custom/{$themeName}";
      $label    = $themePreset ?? '(starterkit default)';
      $this->io->writeln("🔨 Installing theme deps and applying preset '{$label}'...");

      $code = $this->runProcess(['npm', 'install'], $themeDir);
      if ($code === 0) {
        // npm run preset [-- <name>] [--no-build]
        $preset = ['npm', 'run', 'preset'];
        $extra  = [];
        if ($themePreset) {
          $extra[] = $themePreset;
        }
        if (!$themeBuild) {
          $extra[] = '--no-build';
        }
        if ($extra) {
          $preset[] = '--';
          $preset = array_merge($preset, $extra);
        }
        $code = $this->runProcess($preset, $themeDir);
      }

      if ($code !== 0) {
        $this->io->error("Theme preset/build failed. Check that npm is available and the theme builds.");
        return $code;
      }

      $this->io->writeln($themeBuild
        ? '✅ Preset applied and theme built.'
        : "ℹ️  Preset staged; build skipped (build: false). Run `npm run build` inside themes/custom/{$themeName} when ready.");
    }

    $this->io->writeln('🎉 [drupalquick] Scaffold complete. Rebuilding caches...');
    Drush::drush(Drush::aliasManager()->getSelf(), 'cache:rebuild')->mustRun();

    // Enable Twig development mode for live theme iteration (twig debug +
    // auto-reload, render/page/dynamic caches off). This is Drupal's own
    // development-settings mechanism (a key-value in the DB, not a file), so it
    // survives rebuilds, is never committed or deployed, and reverses with
    // `drush theme:dev off`. dq:static turns it off for the export so no debug
    // comments leak into the static HTML.
    if ($enableThemeDev) {
      $this->io->writeln('🧑‍🎨 [drupalquick] Enabling Twig development mode (drush theme:dev on)...');
      $devProc = Drush::drush(Drush::aliasManager()->getSelf(), 'theme:dev', ['on'], ['yes' => TRUE]);
      $devProc->run();
      if ($devProc->isSuccessful()) {
        $this->io->writeln('   Twig debug + auto-reload on, caches off. Turn off with `drush theme:dev off`.');
      }
      else {
        $this->io->warning('Could not enable Twig development mode (drush theme:dev on). Needs Drush 13.6+. Skipped; the build is otherwise complete.');
      }
    }

    return self::SUCCESS;
  }

  /**
   * Generates a strong random password for the admin account.
   */
  private function generatePassword(int $length = 20): string {
    $raw = rtrim(strtr(base64_encode(random_bytes($length)), '+/', '-_'), '=');
    return substr($raw, 0, $length);
  }

  /**
   * Returns the recipe registry, keyed by short recipe name.
   */
  private function registry(): array {
    $file = dirname(__DIR__, 3) . '/templates/recipe-registry.json';
    if (!file_exists($file)) {
      return [];
    }
    return Json::decode(file_get_contents($file)) ?? [];
  }

  /**
   * Resolves a recipe entry to the path `drush recipe` expects.
   *
   * Registry keys and inline package specs ({package, url}) resolve to the
   * project-root path where core-recipe-unpack unpacked the package
   * (recipes/<package-short-name>); core/contrib path strings pass through.
   */
  private function resolvePath($recipe, array $registry): string {
    $package = RecipeEntry::packageFor($recipe, $registry);
    if ($package === NULL) {
      // A core/contrib path string (e.g. core/recipes/standard) — unchanged.
      return $recipe;
    }
    $short = ($pos = strpos($package, '/')) !== FALSE ? substr($package, $pos + 1) : $package;
    // getcwd() is the project root (where config.dq.yml lives).
    return getcwd() . '/recipes/' . $short;
  }

  /**
   * Copies a recipe's theme-assets/ into the generated theme.
   */
  private function copyThemeAssets(string $recipePath, string $themeName): void {
    $base      = str_starts_with($recipePath, '/') ? '' : $this->drupalRoot() . '/';
    $assetsDir = $base . rtrim($recipePath, '/') . '/theme-assets';
    if (!is_dir($assetsDir)) {
      return;
    }

    $themeDir = $this->drupalRoot() . "/themes/custom/{$themeName}";
    $this->copyDirectory($assetsDir, $themeDir, $themeName);
    $this->io->writeln("   Injected theme assets from {$recipePath}");
  }

  /**
   * Ensures the umbrella module exists and returns its directory.
   *
   * The umbrella is an organisational container (modules/custom/dq_hooks) under
   * which recipe submodules are assembled. It does not need to be enabled —
   * Drupal discovers submodules by scanning the filesystem regardless — but it
   * gives the recipe-contributed modules a single, tidy home.
   */
  private function ensureUmbrellaModule(): string {
    $dir = $this->drupalRoot() . '/modules/custom/dq_hooks';
    if (!is_dir($dir)) {
      mkdir($dir, 0755, TRUE);
    }
    $info = "{$dir}/dq_hooks.info.yml";
    if (!file_exists($info)) {
      file_put_contents($info, implode("\n", [
        "name: 'DQ Hooks'",
        'type: module',
        "description: 'Umbrella for drupal-quick recipe behaviour submodules (native OOP hooks).'",
        'core_version_requirement: ^11.3',
        "package: 'DQ'",
        '',
      ]));
    }
    return $dir;
  }

  /**
   * Assembles a recipe's module/ directory into the umbrella as a submodule.
   *
   * The submodule's machine name is taken from the *.info.yml inside module/.
   * No STARTERKIT token substitution is applied — module namespaces are
   * independent of the generated theme's machine name. Returns the machine name
   * (so callers can report it), or NULL when the recipe ships no module.
   */
  private function assembleRecipeModule(string $recipePath, string $umbrellaDir): ?string {
    $base      = str_starts_with($recipePath, '/') ? '' : $this->drupalRoot() . '/';
    $moduleSrc = $base . rtrim($recipePath, '/') . '/module';
    if (!is_dir($moduleSrc)) {
      return NULL;
    }

    $machine = NULL;
    foreach (glob("{$moduleSrc}/*.info.yml") as $infoFile) {
      $machine = basename($infoFile, '.info.yml');
      break;
    }
    if (!$machine) {
      $this->io->warning("Recipe at {$recipePath} has a module/ directory but no *.info.yml; skipping.");
      return NULL;
    }

    // Copy without a theme name so no STARTERKIT substitution happens.
    $this->copyDirectory($moduleSrc, "{$umbrellaDir}/modules/{$machine}");
    return $machine;
  }

  /**
   * Recursively copies files from $src into $dest, merging directories.
   *
   * When $themeName is provided, replaces the literal string STARTERKIT with the
   * theme machine name in both file contents and filenames for text file types.
   */
  private function copyDirectory(string $src, string $dest, string $themeName = ''): void {
    $textTypes = ['php', 'inc', 'module', 'install', 'theme', 'engine', 'profile', 'twig', 'yml', 'yaml', 'js', 'css', 'md', 'txt'];

    if (!is_dir($dest)) {
      mkdir($dest, 0755, TRUE);
    }

    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($src, \RecursiveDirectoryIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
      $subPath = $themeName
        ? str_replace('STARTERKIT', $themeName, $iterator->getSubPathname())
        : $iterator->getSubPathname();

      $target = $dest . '/' . $subPath;

      if ($item->isDir()) {
        if (!is_dir($target)) {
          mkdir($target, 0755, TRUE);
        }
      }
      else {
        $ext = strtolower(pathinfo($item->getPathname(), PATHINFO_EXTENSION));
        if ($themeName && in_array($ext, $textTypes, TRUE)) {
          $content = file_get_contents($item->getPathname());
          file_put_contents($target, str_replace('STARTERKIT', $themeName, $content));
        }
        else {
          copy($item->getPathname(), $target);
        }
      }
    }
  }

  /**
   * Recursively removes a directory and its contents. No-op if absent.
   */
  private function removeDirectory(string $dir): void {
    if (!is_dir($dir)) {
      return;
    }
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
      $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
  }

  /**
   * Maps a config.dq.yml theme_design key to a Tailwind v4 @theme token name.
   */
  private function designTokenName(string $key): string {
    if (str_ends_with($key, '_color')) {
      return '--color-' . str_replace('_', '-', substr($key, 0, -6));
    }
    if ($key === 'font_family') {
      return '--font-sans';
    }
    return '--' . str_replace('_', '-', $key);
  }

}
