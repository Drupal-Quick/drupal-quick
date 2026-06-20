<?php

namespace DrupalQuick\Drush\Commands;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Serialization\Yaml;
use Drush\Commands\DrushCommands;
use Drush\Drush;

/**
 * Drush commands for drupalquick site scaffolding.
 *
 * dq:scaffold orchestrates the full site build driven by config.dq.yml:
 *   1. Installs Drupal with the minimal profile.
 *   2. Generates a custom theme by copying the bundled starterkit, applying
 *      the chosen skin, and injecting CSS custom properties.
 *   3. Applies each recipe in declaration order, injecting recipe-specific
 *      theme assets (templates, includes) into the generated theme as it goes.
 *   4. Runs post-recipe config overrides declared in config.dq.yml parameters.
 *   5. Compiles theme assets via npm/Vite.
 *
 * dq:cleanup removes all scaffolding artifacts (config.dq.yml and this
 * package itself), leaving a self-contained Drupal project.
 *
 * Path context: getcwd() is the directory Drush was invoked from — normally
 * the Composer project root (one level above the web docroot), which is where
 * config.dq.yml lives. The Drupal web root is obtained from $this->drupalRoot();
 * all theme and webroot-relative writes are anchored to it so they work
 * regardless of where Drush is run from. Package-internal assets (starterkit,
 * skins, bundled recipes) are addressed with absolute paths via __DIR__.
 */
class DrupalQuickCommands extends DrushCommands {

  /**
   * Returns the recipe registry, keyed by short recipe name.
   *
   * Each entry may contain:
   *   package      — Composer package name (e.g. drupal-quick/recipe-blog)
   *   url          — VCS URL used by dq-install for external recipes
   *   path         — Recipe directory path: relative to package root for
   *                  bundled recipes, relative to project root for external
   *   bundled      — TRUE when the recipe ships inside this package
   *   theme_assets — TRUE when the recipe ships a theme-assets/ directory
   *
   * Keys absent from the registry are treated as literal paths by resolvePath()
   * and passed directly to drush recipe (e.g. "core/recipes/standard").
   */
  /**
   * Returns the Drupal web root (the docroot, e.g. the project's web/ dir).
   *
   * Resolved via Drush's bootstrap manager rather than \Drupal::root() because
   * dq:scaffold runs before/around a site:install and the service container is
   * not guaranteed to be initialized when these paths are needed. getcwd() is
   * not used here either: Drush is normally invoked from the project root (one
   * level above the docroot), which is where config.dq.yml lives, not where
   * themes belong.
   */
  private function drupalRoot(): string {
    return \Drush\Drush::bootstrapManager()->getRoot();
  }

  private function registry(): array {
    $file = dirname(__DIR__, 3) . '/templates/recipe-registry.json';
    if (!file_exists($file)) {
      return [];
    }
    return Json::decode(file_get_contents($file)) ?? [];
  }

  /**
   * Resolves a recipe key to the path drush recipe expects.
   *
   * Bundled registry entries are resolved to an absolute path inside vendor/
   * so drush recipe can find them regardless of working directory.
   *
   * Non-registry entries (core/*, contrib/*) are passed through unchanged —
   * Drupal's recipe system resolves them relative to DRUPAL_ROOT.
   *
   * @todo (long-term) External registry entries (bundled: false) currently
   *   return a path relative to the project root (e.g. vendor/drupal-quick/
   *   recipe-blog). Since getcwd() is DRUPAL_ROOT (web/), that relative path
   *   will not resolve. When the first external recipe is added, update the
   *   non-bundled branch to return an absolute path:
   *     return dirname(__DIR__, 6) . '/' . $info['path'];
   *   where dirname(__DIR__, 6) is the Composer project root.
   */
  private function resolvePath(string $recipe, array $registry): string {
    if (!isset($registry[$recipe])) {
      return $recipe;
    }
    $info = $registry[$recipe];
    // Bundled recipes live inside the drupal-quick package. Return an absolute
    // path so drush recipe can find them regardless of the working directory.
    if (!empty($info['bundled'])) {
      return dirname(__DIR__, 3) . '/' . $info['path'];
    }
    return $info['path'];
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
    // recipePath may be an absolute path (bundled recipes) or relative to the
    // Drupal root (core/contrib recipes). Detect and prefix accordingly.
    $base      = str_starts_with($recipePath, '/') ? '' : $this->drupalRoot() . '/';
    $assetsDir = $base . rtrim($recipePath, '/') . '/theme-assets';
    if (!is_dir($assetsDir)) {
      return;
    }

    $themeDir = $this->drupalRoot() . "/themes/custom/{$themeName}";
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
    // Extensions whose contents get STARTERKIT → machine-name substitution.
    // 'theme' is essential: the starterkit's .theme file defines the hook
    // functions (STARTERKIT_preprocess, the add_preprocessor helper, etc.) that
    // must be renamed to match the generated theme, or Drupal won't invoke them
    // and recipe includes calling the helper will hit an undefined function.
    $textTypes = ['php', 'inc', 'module', 'install', 'theme', 'engine', 'profile', 'twig', 'yml', 'yaml', 'js', 'css', 'md', 'txt'];

    // Ensure the destination root exists before copying. The iterator below
    // only creates subdirectories as it encounters them, so files living at the
    // top level of $src would otherwise fail to write when $dest is absent.
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
   * Recursively removes a directory and its contents.
   *
   * Used to clean up the starterkit staged into the web root during theme
   * generation. No-op if the directory does not exist.
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
   * Extracts CSS custom property declarations (--name: value;) from CSS text.
   *
   * Used to read theme tokens from the starterkit's main.css and from a skin
   * file so they can be merged. Returns an ordered map of token => value.
   */
  private function parseThemeTokens(string $css): array {
    $tokens = [];
    if (preg_match_all('/(--[\w-]+)\s*:\s*([^;]+);/', $css, $matches, PREG_SET_ORDER)) {
      foreach ($matches as $match) {
        $tokens[$match[1]] = trim($match[2]);
      }
    }
    return $tokens;
  }

  /**
   * Maps a config.dq.yml theme_design key to a Tailwind v4 @theme token name.
   *
   *   <name>_color → --color-<name>  (drives bg-/text-/border-<name> utilities)
   *   font_family  → --font-sans
   *   anything else → --<kebab-key>
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

  /**
   * Rewrites the dq:theme block in main.css with the merged token set.
   *
   * The tokens go in a `@theme static` block in the entry stylesheet — the only
   * place Tailwind v4 processes @theme — so they drive utilities and are always
   * emitted as CSS variables. Replaces the content between the dq:theme markers.
   */
  private function writeThemeTokens(string $mainCss, array $tokens): void {
    $block = "/* dq:theme:start */\n@theme static {\n";
    foreach ($tokens as $name => $value) {
      $block .= "  {$name}: {$value};\n";
    }
    $block .= "}\n/* dq:theme:end */";

    $css = file_get_contents($mainCss);
    $css = preg_replace('/\/\* dq:theme:start \*\/.*?\/\* dq:theme:end \*\//s', $block, $css, 1, $count);
    if (!$count) {
      // Markers missing (customized main.css) — append the block instead.
      $css .= "\n" . $block . "\n";
    }
    file_put_contents($mainCss, $css);
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
      $config = Yaml::decode(file_get_contents($configFile));
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
    // The minimal profile is intentional — it installs the bare minimum with
    // no content types, views, or default blocks. Recipes applied in step 3
    // layer in exactly what the project needs, keeping the installed config
    // set clean. Using the standard profile here would produce config that
    // recipes then partially override, leaving orphaned configuration behind.
    $this->output()->writeln('⚙️  Installing Drupal...');
    Drush::drush(Drush::aliasManager()->getSelf(), 'site:install', ['minimal'], [
      'site-name'    => $siteName,
      'account-name' => $accountName,
      'account-pass' => $accountPass,
      'yes'          => TRUE,
    ])->mustRun();

    // 2. Generate theme from the starterkit using Drupal core's native
    // generate-theme command.
    //
    // The bundled starterkit lives inside this package, which Composer installs
    // outside the web root (this package is type drupal-drush), so Drupal's
    // theme discovery — which generate-theme relies on — cannot see it there.
    // To bridge that, we stage the starterkit inside the web root's themes/
    // directory just long enough for the command to find and process it, then
    // remove the temporary copy. The starterkit follows core's starter kit
    // convention (a dq_starterkit.starterkit.yml marker plus machine-name-based
    // token substitution), so generate-theme performs the rename, .info.yml
    // rewrite, and file processing for us — no hand-rolled mimic required.
    //
    // @todo (long-term) Extract dq_starterkit into its own Composer package of
    //   type "drupal-theme" (e.g. drupal-quick/dq-starterkit) and require it
    //   here. Composer would install it at web/themes/contrib/dq_starterkit/
    //   where Drupal discovers it natively, letting us drop the staging step
    //   below and point generate-theme straight at the installed starterkit.
    if ($themeName) {
      $this->output()->writeln("🎨 Generating theme '{$themeName}' from starterkit...");

      $starterkitId     = 'dq_starterkit';
      $starterkitSource = dirname(__DIR__, 3) . '/starterkits/' . $starterkitId;
      $drupalRoot       = $this->drupalRoot();
      $themeDir         = "{$drupalRoot}/themes/custom/{$themeName}";

      // Stage the starterkit inside the web root so theme discovery can find it.
      $stagedStarterkit = "{$drupalRoot}/themes/{$starterkitId}";
      $this->removeDirectory($stagedStarterkit);
      $this->copyDirectory($starterkitSource, $stagedStarterkit);

      // Delegate to core. generate-theme chdir()s to the Drupal root, so --path
      // is resolved relative to the web root. It copies the staged starterkit to
      // a temp dir, rewrites dq_starterkit → the new machine name in file
      // contents and names, rebuilds the .info.yml (name, version, generator),
      // and writes the finished theme to themes/custom/{machine}.
      $generate = sprintf(
        'php %s generate-theme %s --name=%s --description=%s --path=%s --starterkit=%s',
        escapeshellarg("{$drupalRoot}/core/scripts/drupal"),
        escapeshellarg($themeName),
        escapeshellarg($themeTitle),
        escapeshellarg('A custom Drupal theme built with Tailwind CSS and Vite.'),
        escapeshellarg('themes/custom'),
        escapeshellarg($starterkitId)
      );
      passthru($generate, $genCode);

      // Remove the staged starterkit whether generation succeeded or not.
      $this->removeDirectory($stagedStarterkit);

      if ($genCode !== 0 || !is_dir($themeDir)) {
        $this->logger()->error("Theme generation failed (generate-theme exit code {$genCode}).");
        return $genCode ?: 1;
      }

      // Strip the generator line generate-theme adds (e.g. "dq_starterkit:1.0.0")
      // so the finished theme carries no reference back to the starterkit.
      $infoFile = "{$themeDir}/{$themeName}.info.yml";
      if (file_exists($infoFile)) {
        $info = preg_replace('/^generator:.*\n?/m', '', file_get_contents($infoFile));
        file_put_contents($infoFile, $info);
      }

      // Enable the theme now so it exists while recipes are applied. Setting it
      // as the site default is deferred until after recipes run (see step 3.5),
      // because recipes such as core/recipes/standard set their own default
      // theme (Olivero) and would otherwise clobber this selection.
      Drush::drush(Drush::aliasManager()->getSelf(), 'theme:enable', [$themeName], ['yes' => TRUE])->mustRun();

      // Build the theme tokens by layering: the starterkit defaults in main.css
      // ← the chosen skin ← config.dq.yml theme_design. They must end up in the
      // entry main.css @theme block, because Tailwind v4 only processes @theme
      // there (not in @import-ed files). resolveThemeTokens() merges them and
      // writeThemeTokens() rewrites the dq:theme block in main.css.
      $this->output()->writeln("🖌️  Applying '{$themeStyle}' skin and theme_design tokens...");
      $mainCss = "{$themeDir}/src/main.css";
      $tokens  = $this->parseThemeTokens(file_get_contents($mainCss));

      $skinSrc = dirname(__DIR__, 3) . "/starterkits/skins/{$themeStyle}.css";
      if (file_exists($skinSrc)) {
        $tokens = array_merge($tokens, $this->parseThemeTokens(file_get_contents($skinSrc)));
      }
      else {
        $this->logger()->warning("Skin '{$themeStyle}' not found at {$skinSrc}. Using starterkit defaults.");
      }

      foreach ($parameters['theme_design'] ?? [] as $key => $value) {
        $tokens[$this->designTokenName($key)] = $value;
      }

      $this->writeThemeTokens($mainCss, $tokens);
    }

    // 3. Apply recipes.
    // Recipes are applied in the order declared in config.dq.yml. resolvePath()
    // translates registry keys to paths drush recipe can locate; core/* and
    // contrib/* paths are passed through unchanged. After each registry-managed
    // recipe with theme_assets: true, its templates/ and includes/ are merged
    // into the generated theme directory so only assets from applied recipes
    // are present in the theme.
    //
    // @todo (long-term) As bundled recipes graduate to their own packages,
    //   update each registry entry (remove "bundled": true, add real VCS url)
    //   and update resolvePath() to return absolute paths for external entries
    //   (see the @todo in that method).
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

    // 3.5. Set the generated theme as the site default.
    // Deferred until after recipes so it wins over any default theme a recipe
    // sets (core/recipes/standard sets Olivero). The theme was already enabled
    // in step 2 so it exists and is ready to become the active default here.
    if ($themeName) {
      $this->output()->writeln("🎨 Setting '{$themeName}' as the default theme...");
      Drush::drush(Drush::aliasManager()->getSelf(), 'config:set', ['system.theme', 'default', $themeName], ['yes' => TRUE])->mustRun();
    }

    // 4. Post-recipe config overrides.
    // The recipe_config block in config.dq.yml allows arbitrary drush config:set
    // calls after all recipes have been applied. Running these last ensures they
    // win over any defaults a recipe may have set (e.g. site slogan, front page
    // path, date formats). Recipes cannot anticipate every project-level
    // preference, so this is the escape hatch for the remainder.
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
    // Runs `npm install && npm run build` inside the generated theme directory.
    // The theme uses Vite with Tailwind CSS v4; the build outputs dist/main.js
    // and dist/main.css which the theme's .libraries.yml references. Set
    // build: false in config.dq.yml to skip this and build manually or in CI.
    if ($themeName && $themeBuild) {
      $themeDir = $this->drupalRoot() . "/themes/custom/{$themeName}";
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
   * Resolves static-export settings.
   *
   * Persisted Drupal config (drupalquick.static) wins because it survives
   * dq:cleanup, which deletes config.dq.yml. On the first run no persisted
   * config exists yet, so the static: block in config.dq.yml seeds it.
   */
  private function staticSettings($self): array {
    // config:get exits non-zero when the object does not exist yet; tolerate it.
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
   * Writes the deploy configuration for the chosen target into the project.
   *
   * netlify → netlify.toml at the project root.
   * github  → .github/workflows/deploy-pages.yml.
   * none    → nothing.
   */
  private function emitDeployTemplate(string $target): void {
    $deployDir   = dirname(__DIR__, 3) . '/templates/deploy';
    $projectRoot = getcwd();

    if ($target === 'netlify') {
      $src = "{$deployDir}/netlify.toml";
      if (file_exists($src)) {
        copy($src, "{$projectRoot}/netlify.toml");
        $this->output()->writeln('   Wrote netlify.toml');
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
        $this->output()->writeln('   Wrote .github/workflows/deploy-pages.yml');
      }
    }
  }

  /**
   * Generates a static HTML export of the site with Tome and writes a deploy
   * config for the configured target.
   *
   * Static export is a recurring, post-build operation, so it lives in this
   * command rather than a recipe (recipes apply at build time and cannot run an
   * export). Settings are seeded from the static: block in config.dq.yml on the
   * first run and persisted to Drupal config (drupalquick.static) so they
   * survive dq:cleanup, which deletes config.dq.yml.
   *
   * @command dq:static
   * @aliases dqst
   * @option base-url The production base URL for absolute links, passed to Tome as --uri (overrides config).
   * @option deploy After exporting, deploy to the configured target (Netlify only for now).
   * @usage drush dq:static
   * @usage drush dq:static --base-url=https://example.com
   * @usage drush dq:static --deploy
   */
  public function staticExport($options = ['base-url' => NULL, 'deploy' => FALSE]) {
    $self = Drush::aliasManager()->getSelf();

    // 1. Resolve settings (persisted config wins; fall back to config.dq.yml).
    $settings = $this->staticSettings($self);
    $target   = $settings['target'] ?? 'none';
    $uri      = $options['base-url'] ?? ($settings['uri'] ?? NULL);

    // 2. Ensure Tome static is installed and enabled.
    $this->output()->writeln('📦 [drupalquick] Ensuring Tome static is available...');
    $hasTome = trim((string) Drush::drush($self, 'php:eval', ["echo \\Drupal::moduleHandler()->moduleExists('tome_static') ? '1' : '0';"])->mustRun()->getOutput()) === '1';
    if (!$hasTome) {
      $this->output()->writeln('   Installing drupal/tome via Composer...');
      passthru('composer require drupal/tome', $code);
      if ($code !== 0) {
        $this->logger()->error('composer require drupal/tome failed.');
        return 1;
      }
      Drush::drush($self, 'pm:install', ['tome_static'], ['yes' => TRUE])->mustRun();
    }

    // 3. Persist settings to Drupal config so re-exports work after cleanup.
    Drush::drush($self, 'config:set', ['drupalquick.static', 'target', $target], ['yes' => TRUE])->mustRun();
    if ($uri) {
      Drush::drush($self, 'config:set', ['drupalquick.static', 'uri', $uri], ['yes' => TRUE])->mustRun();
    }

    // 4. Preflight the active theme: it must be built, and the Vite dev marker
    //    must be absent or the export would capture localhost dev-server tags.
    $theme    = trim((string) Drush::drush($self, 'config:get', ['system.theme', 'default', '--format=string'])->mustRun()->getOutput());
    $themeDir = $this->drupalRoot() . "/themes/custom/{$theme}";
    if (is_dir($themeDir)) {
      if (file_exists("{$themeDir}/.vite-dev")) {
        $this->logger()->error("The Vite dev server appears to be running ({$theme}/.vite-dev exists). Stop it and run `npm run build` before exporting.");
        return 1;
      }
      if (!file_exists("{$themeDir}/dist/main.css")) {
        $this->logger()->warning("Theme '{$theme}' has no built assets (dist/main.css missing). Run `npm install && npm run build` in {$themeDir} for styled output.");
      }
    }

    // 5. Run the static export.
    $this->output()->writeln('🧊 [drupalquick] Generating static site with Tome...');
    $opts = ['yes' => TRUE];
    if ($uri) {
      $opts['uri'] = $uri;
    }
    Drush::drush($self, 'tome:static', [], $opts)->mustRun();

    // 6. Emit the deploy template for the chosen target.
    $this->emitDeployTemplate($target);

    $this->output()->writeln('✅ [drupalquick] Static export complete.');
    $this->output()->writeln("   Output: html/ (Tome default; override via \$settings['tome_static_directory'] in settings.php).");

    // 7. Optionally deploy the export to the configured target.
    if ($options['deploy']) {
      return $this->deployStatic($target);
    }

    return 0;
  }

  /**
   * Deploys the static export (html/) to the configured target.
   *
   * Only Netlify is automated for now: it runs `netlify deploy --prod`, using a
   * globally installed CLI if present, otherwise `npx netlify-cli`. The CLI must
   * be authenticated (interactive `netlify login`, or NETLIFY_AUTH_TOKEN) and
   * the site linked (via netlify.toml, `netlify link`, or NETLIFY_SITE_ID).
   * GitHub Pages deploys via its own workflow (git push), so it is a no-op here.
   */
  private function deployStatic(string $target): int {
    if ($target !== 'netlify') {
      $this->logger()->warning("--deploy currently automates the 'netlify' target only. For '{$target}', deploy via its own workflow (e.g. git push for GitHub Pages).");
      return 0;
    }

    // Prefer a globally installed CLI; fall back to npx (Node is available in
    // the build environment for the theme's Vite build).
    $bin = trim((string) shell_exec('command -v netlify 2>/dev/null'));
    $cli = $bin !== '' ? 'netlify' : 'npx --yes netlify-cli';

    $this->output()->writeln("🚀 [drupalquick] Deploying to Netlify ({$cli})...");
    passthru("{$cli} deploy --prod --dir=html", $code);

    if ($code !== 0) {
      $this->logger()->error('Netlify deploy failed. The CLI must be authenticated inside the container: copy .ddev/.env.web.example to .ddev/.env.web, set NETLIFY_AUTH_TOKEN (and optionally NETLIFY_SITE_ID), then run `ddev restart` and retry. Prefer to keep secrets out of the container? Deploy from the host instead, where `netlify login` stored credentials: `netlify deploy --prod --dir=html`.');
      return $code;
    }

    $this->output()->writeln('✅ [drupalquick] Netlify deploy complete.');
    return 0;
  }

  /**
   * Removes drupalquick scaffolding artifacts from the project.
   *
   * Always removes the drupalquick package via Composer — the tool deletes its
   * own code, leaving a self-contained project. By default config.dq.yml is
   * archived in place (commented out with a header) so it survives as a
   * reference of how the site was scaffolded. Pass --purge to delete it
   * entirely for zero trace.
   *
   * @command dq:cleanup
   * @option force Skip the confirmation prompt.
   * @option purge Delete config.dq.yml entirely instead of archiving it.
   * @aliases dqc
   * @usage drush dq:cleanup
   * @usage drush dq:cleanup --purge
   * @usage drush dq:cleanup --force --purge
   */
  public function cleanup($options = ['force' => FALSE, 'purge' => FALSE, 'remove-everything' => FALSE]) {
    $purge = $options['purge'] || $options['remove-everything'];

    if (!$options['force']) {
      $action = $purge
        ? 'delete config.dq.yml and remove the drupalquick package'
        : 'archive config.dq.yml (commented, kept as reference) and remove the drupalquick package';
      $confirmed = $this->io()->confirm("This will {$action}. Continue?", FALSE);
      if (!$confirmed) {
        $this->output()->writeln('Cleanup cancelled.');
        return 0;
      }
    }

    $projectRoot = getcwd();
    $configFile  = "{$projectRoot}/config.dq.yml";

    if (!file_exists($configFile)) {
      $this->output()->writeln('ℹ️  config.dq.yml not found — already removed.');
    }
    elseif ($purge) {
      unlink($configFile);
      $this->output()->writeln('✅ Deleted config.dq.yml');
    }
    else {
      $this->archiveConfig($configFile);
      $this->output()->writeln('✅ Archived config.dq.yml (commented out as a reference; safe to delete)');
    }

    $this->output()->writeln('🧹 Removing drupalquick package via Composer...');
    passthru('composer remove drupal-quick/drupal-quick', $exitCode);

    if ($exitCode !== 0) {
      $this->logger()->error('Composer remove failed. Run `composer remove drupal-quick/drupal-quick` manually.');
      return 1;
    }

    $this->output()->writeln('✅ drupalquick removed. The project is self-contained.');
    return 0;
  }

  /**
   * Comments out config.dq.yml in place and prepends a one-line header so it
   * remains as an inert, human-readable reference of how the site was built.
   */
  private function archiveConfig(string $configFile): void {
    $header = "# Archived by drupalquick on " . date('Y-m-d') . " — reference only.\n"
      . "# This is the (commented-out) configuration used to scaffold this site.\n"
      . "# drupalquick has been removed; this file is inert and safe to delete.\n\n";
    $body = preg_replace('/^/m', '# ', file_get_contents($configFile));
    file_put_contents($configFile, $header . $body);
  }

}
