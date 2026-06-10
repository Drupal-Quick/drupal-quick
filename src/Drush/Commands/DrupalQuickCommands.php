<?php

namespace DrupalQuick\Drush\Commands;

use Drush\Commands\DrushCommands;
use Symfony\Component\Yaml\Yaml;
use Drush\Drush;

/**
 * Drush commands for drupalquick site scaffolding.
 */
class DrupalQuickCommands extends DrushCommands {

  /**
   * Returns the recipe registry, keyed by short recipe name.
   */
  private function registry(): array {
    $file = dirname(__DIR__, 3) . '/templates/recipe-registry.json';
    if (!file_exists($file)) {
      return [];
    }
    return json_decode(file_get_contents($file), true) ?? [];
  }

  /**
   * Resolves a recipe entry to the path drush recipe expects.
   *
   * Short keys (e.g. "blog") are looked up in the registry and translated to
   * their vendor path. Everything else is passed through as-is.
   */
  private function resolvePath(string $recipe, array $registry): string {
    return isset($registry[$recipe])
      ? $registry[$recipe]['path']
      : $recipe;
  }

  /**
   * Copies theme-assets from a recipe package into the generated theme.
   *
   * Recipe packages may ship a theme-assets/ directory containing:
   *   templates/ — Twig templates copied to themes/custom/{theme}/templates/
   *   includes/  — PHP .theme.inc files copied to themes/custom/{theme}/includes/
   *
   * All text file contents and filenames have STARTERKIT replaced with the
   * actual theme machine name, mirroring what drush theme:starterkit does
   * when generating the theme. This ensures that calls to
   * STARTERKIT_add_preprocessor() inside recipe includes resolve to the
   * correct function name in the generated theme.
   *
   * The starterkit's THEMENAME.theme auto-discovers includes/ via glob, so
   * only functions from applied recipes are ever present in the theme.
   */
  private function copyThemeAssets(string $recipePath, string $themeName): void {
    $assetsDir = getcwd() . "/{$recipePath}/theme-assets";
    if (!is_dir($assetsDir)) {
      return;
    }

    $themeDir = getcwd() . "/themes/custom/{$themeName}";
    $this->copyDirectory($assetsDir, $themeDir, $themeName);
    $this->output()->writeln("   Injected theme assets from {$recipePath}");
  }

  /**
   * Recursively copies files from $src into $dest, merging directories.
   *
   * When $themeName is provided, replaces the literal string STARTERKIT with
   * the theme machine name in both file contents and filenames for all
   * recognised text file types.
   */
  private function copyDirectory(string $src, string $dest, string $themeName = ''): void {
    $textTypes = ['php', 'inc', 'twig', 'yml', 'yaml', 'js', 'css', 'md', 'txt'];

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
   * Scaffolds a Drupal site using config.dq.yml.
   *
   * @command dq:scaffold
   * @aliases dqs
   * @option interactive Prompt the user to fill out or override config values interactively.
   */
  public function scaffold($options = ['interactive' => FALSE]) {
    $configFile = getcwd() . '/config.dq.yml';

    if (!file_exists($configFile)) {
      $this->logger()->error('config.dq.yml not found. Run "composer exec dq-init" first.');
      return 1;
    }

    $this->output()->writeln('⚡ [drupalquick] Processing configuration...');
    try {
      $config = Yaml::parseFile($configFile);
    }
    catch (\Exception $e) {
      $this->logger()->error('Error parsing config.dq.yml: ' . $e->getMessage());
      return 1;
    }

    $registry = $this->registry();

    // --- Interactive prompts ---
    if ($options['interactive']) {
      $this->output()->writeln("\n💬 [drupalquick] Entering interactive configuration mode...");

      $config['site']['name'] = $this->io()->ask(
        'What is the name of this Drupal site?',
        $config['site']['name'] ?? 'Drupal Site'
      );

      $skinsDir       = dirname(__DIR__, 3) . '/starterkits/skins';
      $availableSkins = ['minimal', 'corporate'];
      if (is_dir($skinsDir)) {
        $discovered = [];
        foreach (scandir($skinsDir) as $file) {
          if (pathinfo($file, PATHINFO_EXTENSION) === 'css') {
            $discovered[] = pathinfo($file, PATHINFO_FILENAME);
          }
        }
        if ($discovered) {
          $availableSkins = array_values($discovered);
        }
      }

      $config['theme']['style'] = $this->io()->choice(
        'Which design skin would you like to apply?',
        $availableSkins,
        $config['theme']['style'] ?? 'minimal'
      );

      $this->output()->writeln("✅ Configuration updated for this session.\n");
    }

    $siteName    = $config['site']['name'] ?? 'Drupal Site';
    $accountName = $config['site']['admin_user'] ?? 'admin';
    $accountPass = $config['site']['admin_pass'] ?? 'admin';
    $themeName   = $config['theme']['machine_name'] ?? NULL;
    $themeTitle  = $config['theme']['title'] ?? 'Custom Theme';
    $themeStyle  = $config['theme']['style'] ?? 'minimal';
    $themeBuild  = $config['theme']['build'] ?? TRUE;
    $recipes     = $config['recipes'] ?? [];
    $parameters  = $config['parameters'] ?? [];

    // 1. Install Drupal.
    $this->output()->writeln('⚙️  Installing Drupal...');
    Drush::drush(Drush::aliasManager()->getSelf(), 'site:install', ['minimal'], [
      'site-name'    => $siteName,
      'account-name' => $accountName,
      'account-pass' => $accountPass,
      'yes'          => TRUE,
    ])->mustRun();

    // 2. Generate theme from starterkit.
    if ($themeName) {
      $this->output()->writeln("🎨 Generating theme '{$themeName}' from starterkit...");
      $starterkitPath = dirname(__DIR__, 3) . '/starterkits/dq_starterkit';

      Drush::drush(Drush::aliasManager()->getSelf(), 'theme:starterkit', [$themeName], [
        'theme-name' => $themeTitle,
        'starterkit' => $starterkitPath,
      ])->mustRun();

      Drush::drush(Drush::aliasManager()->getSelf(), 'theme:enable', [$themeName], ['yes' => TRUE])->mustRun();
      Drush::drush(Drush::aliasManager()->getSelf(), 'config:set', ['system.theme', 'default', $themeName], ['yes' => TRUE])->mustRun();

      // Bake the chosen skin into the generated theme.
      $skinSrc  = dirname(__DIR__, 3) . "/starterkits/skins/{$themeStyle}.css";
      $skinDest = "themes/custom/{$themeName}/src/theme-skin.css";
      if (file_exists($skinSrc)) {
        $this->output()->writeln("🖌️  Applying '{$themeStyle}' skin tokens...");
        $dir = dirname($skinDest);
        if (!is_dir($dir)) {
          mkdir($dir, 0755, TRUE);
        }
        copy($skinSrc, $skinDest);
      }
      else {
        $this->logger()->warning("Skin '{$themeStyle}' not found at {$skinSrc}. Skipping skin step.");
      }

      // Write theme_design parameters as CSS custom properties.
      if (!empty($parameters['theme_design'])) {
        $this->output()->writeln('🎨 Injecting theme_design parameters as CSS custom properties...');
        $cssDir = "themes/custom/{$themeName}/css";
        if (!is_dir($cssDir)) {
          mkdir($cssDir, 0755, TRUE);
        }
        $css = "/* Generated by drupalquick */\n:root {\n";
        foreach ($parameters['theme_design'] as $var => $value) {
          $css .= '  --dq-' . str_replace('_', '-', $var) . ": {$value};\n";
        }
        $css .= "}\n";
        file_put_contents("{$cssDir}/drupalquick-vars.css", $css);
      }
    }

    // 3. Apply recipes.
    // Short registry keys are translated to their vendor path; everything
    // else (core/contrib paths) is passed through unchanged. After each
    // registry-managed recipe is applied, any theme-assets it ships are
    // copied into the generated theme.
    if (!empty($recipes)) {
      $this->output()->writeln('📦 Applying Drupal recipes...');
      foreach ($recipes as $recipe) {
        $path = $this->resolvePath($recipe, $registry);
        if ($path !== $recipe) {
          $this->output()->writeln("   Resolved '{$recipe}' → {$path}");
        }
        Drush::drush(Drush::aliasManager()->getSelf(), 'recipe', [$path], ['yes' => TRUE])->mustRun();

        // Inject recipe-specific theme assets if the recipe ships them and
        // a custom theme was generated during this scaffold run.
        if ($themeName && isset($registry[$recipe]) && !empty($registry[$recipe]['theme_assets'])) {
          $this->copyThemeAssets($path, $themeName);
        }
      }
    }

    // 4. Post-recipe config overrides.
    if (!empty($parameters['recipe_config'])) {
      $this->output()->writeln('⚙️  Applying recipe_config parameter overrides...');
      foreach ($parameters['recipe_config'] as $configName => $settings) {
        if (is_array($settings)) {
          foreach ($settings as $key => $value) {
            Drush::drush(Drush::aliasManager()->getSelf(), 'config:set', [$configName, $key, $value], ['yes' => TRUE])->mustRun();
          }
        }
      }
    }

    // 5. Build the theme.
    if ($themeName && $themeBuild) {
      $themeDir = getcwd() . "/themes/custom/{$themeName}";
      $this->output()->writeln("🔨 Building theme assets (npm install && npm run build)...");

      passthru("cd " . escapeshellarg($themeDir) . " && npm install && npm run build", $buildCode);

      if ($buildCode !== 0) {
        $this->logger()->error("Theme build failed. Check that npm is available and the theme's package.json is valid.");
        return $buildCode;
      }

      $this->output()->writeln("✅ Theme build complete.");
    }
    elseif ($themeName && !$themeBuild) {
      $this->output()->writeln("ℹ️  Theme build skipped (build: false in config). Run `npm install && npm run build` inside themes/custom/{$themeName} when ready.");
    }

    $this->output()->writeln('🎉 [drupalquick] Scaffold complete. Rebuilding caches...');
    Drush::drush(Drush::aliasManager()->getSelf(), 'cache:rebuild')->mustRun();

    return 0;
  }

  /**
   * Removes drupalquick scaffolding artifacts from the project.
   *
   * Deletes config.dq.yml from the project root, then removes the
   * drupalquick package via Composer — leaving no trace of the scaffolding
   * tool in the finished project.
   *
   * @command dq:cleanup
   * @option force Skip the confirmation prompt.
   * @usage drush dq:cleanup
   * @usage drush dq:cleanup --force
   */
  public function cleanup($options = ['force' => FALSE]) {
    if (!$options['force']) {
      $confirmed = $this->io()->confirm(
        'This will delete config.dq.yml and remove the drupalquick package. This cannot be undone. Continue?',
        FALSE
      );
      if (!$confirmed) {
        $this->output()->writeln('Cleanup cancelled.');
        return 0;
      }
    }

    $projectRoot = getcwd();
    $configFile  = "{$projectRoot}/config.dq.yml";

    if (file_exists($configFile)) {
      unlink($configFile);
      $this->output()->writeln('✅ Removed config.dq.yml');
    }
    else {
      $this->output()->writeln('ℹ️  config.dq.yml not found — already removed.');
    }

    $this->output()->writeln('🧹 Removing drupalquick package via Composer...');
    passthru('composer remove your-namespace/drupalquick', $exitCode);

    if ($exitCode !== 0) {
      $this->logger()->error('Composer remove failed. Run `composer remove your-namespace/drupalquick` manually.');
      return 1;
    }

    $this->output()->writeln('✅ drupalquick removed. The project is self-contained.');
    return 0;
  }

}
