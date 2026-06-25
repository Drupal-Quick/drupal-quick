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
      ->addUsage('dq:scaffold')
      ->addUsage('dq:scaffold --interactive');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $this->io = new DrushStyle($input, $output);

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

    // --- Interactive prompts ---
    if ($input->getOption('interactive')) {
      $this->io->writeln("\n💬 [drupalquick] Entering interactive configuration mode...");

      $config['site']['name'] = $this->io->ask(
        'What is the name of this Drupal site?',
        $config['site']['name'] ?? 'Drupal Site'
      );

      // Discover skins from the installed starterkit package (extra.dq.skins),
      // falling back to scanning its skins/ directory.
      $starterkitDir  = $this->drupalRoot() . '/themes/contrib/dq_starterkit';
      $availableSkins = [];
      if (file_exists("{$starterkitDir}/composer.json")) {
        $meta = json_decode(file_get_contents("{$starterkitDir}/composer.json"), TRUE) ?: [];
        $availableSkins = $meta['extra']['dq']['skins'] ?? [];
      }
      if (!$availableSkins && is_dir("{$starterkitDir}/skins")) {
        foreach (scandir("{$starterkitDir}/skins") as $file) {
          if (pathinfo($file, PATHINFO_EXTENSION) === 'css') {
            $availableSkins[] = pathinfo($file, PATHINFO_FILENAME);
          }
        }
      }
      if (!$availableSkins) {
        $availableSkins = ['minimal', 'corporate'];
      }

      $config['theme']['style'] = $this->io->choice(
        'Which design skin would you like to apply?',
        $availableSkins,
        $config['theme']['style'] ?? 'minimal'
      );

      $this->io->writeln("✅ Configuration updated for this session.\n");
    }

    // Run the build phases inside one try/catch: every Drush call below uses
    // mustRun(), so any failure aborts with a clear "site may be partially
    // built" message instead of a raw stack trace.
    try {
      return $this->runBuild($config, $registry);
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
  private function runBuild(array $config, array $registry): int {
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
    $themeStyle  = $config['theme']['style'] ?? 'minimal';
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

      $genCode = $this->runProcess([
        'php', "{$drupalRoot}/core/scripts/drupal", 'generate-theme',
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

      // Layer theme tokens: starterkit defaults ← chosen skin ← theme_design.
      // They must land in the entry main.css @theme block (the only place
      // Tailwind v4 processes @theme).
      $this->io->writeln("🖌️  Applying '{$themeStyle}' skin and theme_design tokens...");
      $mainCss = "{$themeDir}/src/main.css";
      $tokens  = $this->parseThemeTokens(file_get_contents($mainCss));

      $skinSrc = "{$starterkitDir}/skins/{$themeStyle}.css";
      if (file_exists($skinSrc)) {
        $tokens = array_merge($tokens, $this->parseThemeTokens(file_get_contents($skinSrc)));
      }
      else {
        $this->io->warning("Skin '{$themeStyle}' not found at {$skinSrc}. Using starterkit defaults.");
      }

      foreach ($parameters['theme_design'] ?? [] as $key => $value) {
        $tokens[$this->designTokenName($key)] = $value;
      }

      $this->writeThemeTokens($mainCss, $tokens);
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
        $path = $this->resolvePath($recipe, $registry);
        if ($path !== $recipe && ($name = $this->assembleRecipeModule($path, $umbrellaDir))) {
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
        $path = $this->resolvePath($recipe, $registry);
        // Managed recipes (a registry key or inline spec) resolve to a recipes/
        // path; core/contrib path strings pass through unchanged.
        $managed = ($path !== $recipe);
        if ($managed) {
          $label = is_array($recipe) ? ($recipe['package'] ?? 'recipe') : $recipe;
          $this->io->writeln("   Resolved '{$label}' → {$path}");
        }
        Drush::drush(Drush::aliasManager()->getSelf(), 'recipe', [$path], ['yes' => TRUE])->mustRun();

        // Inject theme assets when the recipe ships a theme-assets/ directory
        // (copyThemeAssets no-ops when it doesn't — no registry flag needed).
        if ($themeName && $managed) {
          $this->copyThemeAssets($path, $themeName);
        }
      }
    }

    // 3.5. Set the generated theme as the site default (after recipes).
    if ($themeName) {
      $this->io->writeln("🎨 Setting '{$themeName}' as the default theme...");
      Drush::drush(Drush::aliasManager()->getSelf(), 'config:set', ['system.theme', 'default', $themeName], ['yes' => TRUE])->mustRun();
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

    // 5. Build the theme (npm install && npm run build) unless build: false.
    if ($themeName && $themeBuild) {
      $themeDir = $this->drupalRoot() . "/themes/custom/{$themeName}";
      $this->io->writeln('🔨 Building theme assets (npm install && npm run build)...');

      $buildCode = $this->runProcess(['npm', 'install'], $themeDir);
      if ($buildCode === 0) {
        $buildCode = $this->runProcess(['npm', 'run', 'build'], $themeDir);
      }

      if ($buildCode !== 0) {
        $this->io->error("Theme build failed. Check that npm is available and the theme's package.json is valid.");
        return $buildCode;
      }

      $this->io->writeln('✅ Theme build complete.');
    }
    elseif ($themeName && !$themeBuild) {
      $this->io->writeln("ℹ️  Theme build skipped (build: false in config). Run `npm install && npm run build` inside themes/custom/{$themeName} when ready.");
    }

    $this->io->writeln('🎉 [drupalquick] Scaffold complete. Rebuilding caches...');
    Drush::drush(Drush::aliasManager()->getSelf(), 'cache:rebuild')->mustRun();

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
    $package = $this->recipePackage($recipe, $registry);
    if ($package === NULL) {
      // A core/contrib path string (e.g. core/recipes/standard) — unchanged.
      return $recipe;
    }
    $short = ($pos = strpos($package, '/')) !== FALSE ? substr($package, $pos + 1) : $package;
    // getcwd() is the project root (where config.dq.yml lives).
    return getcwd() . '/recipes/' . $short;
  }

  /**
   * Returns the Composer package name for a recipe entry, or NULL for a
   * core/contrib path. Accepts a registry key (string) or an inline spec
   * (['package' => …, 'url' => …]).
   */
  private function recipePackage($recipe, array $registry): ?string {
    if (is_array($recipe)) {
      return $recipe['package'] ?? NULL;
    }
    return $registry[$recipe]['package'] ?? NULL;
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
   * Extracts CSS custom property declarations (--name: value;) from CSS text.
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
      $css .= "\n" . $block . "\n";
    }
    file_put_contents($mainCss, $css);
  }

}
