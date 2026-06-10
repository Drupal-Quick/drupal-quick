# drupal-quick

A Composer package that scaffolds a new Drupal site from a single config file. Run it once, get a working site with a generated custom theme and your chosen recipes applied, then remove it — it leaves no long-term dependency on the project.

> **Work in progress.** The theme and recipe included here are proofs of concept. The core workflow is functional but several pieces described below are still being built out.

---

## How it works

The build runs in three steps after your Drupal project is created:

```bash
# 1. Create the Drupal project and install dependencies
composer create-project drupal/recommended-project my-site
cd my-site
composer require drupal-quick/drupal-quick drush/drush
composer install

# 2. Generate config.dq.yml (add --interactive to use the guided wizard)
composer exec dq-init

# 3. Edit config.dq.yml, then install any recipe packages
composer exec dq-install

# 4. Run the scaffold (installs Drupal, generates theme, applies recipes, builds assets)
drush dq:scaffold
```

To remove all scaffolding artifacts once the site is built:

```bash
drush dq:cleanup
```

---

## config.dq.yml

The config file controls every aspect of the build:

```yaml
site:
  name: "My Site"
  admin_user: "admin"
  admin_pass: "secret"

theme:
  machine_name: "my_theme"
  title: "My Theme"
  style: "minimal"       # matches a file in starterkits/skins/
  build: true            # set false to skip npm build

recipes:
  - "core/recipes/standard"
  - "blog"               # short key from the recipe registry

parameters:
  theme_design:
    primary_color: "#10b981"
    secondary_color: "#1e3a8a"
    font_family: "'Inter', sans-serif"
  recipe_config:
    "system.site":
      slogan: "Built with drupal-quick."
```

---

## Requirements

- PHP 8.1+
- Drupal 10.3+ or 11
- Drush 12.5+
- Node.js / npm (for theme build)

---

## What gets generated

- A custom theme at `web/themes/custom/{machine_name}/` built with Vite and Tailwind CSS v4
- A chosen design skin baked into the theme as a CSS token layer
- CSS custom properties from `theme_design` written into the theme
- Recipe-specific Twig templates and PHP preprocess includes merged into the theme
- All recipes applied in declared order against a minimal Drupal install

---

## To do

- **Separate starterkit package** — Extract `dq_starterkit` into its own Composer package of type `drupal-theme` (`drupal-quick/dq-starterkit`). This allows Composer to place it at `web/themes/contrib/dq_starterkit/` where Drupal's theme system can discover it natively, enabling the scaffold to delegate to `drush theme:starterkit` rather than copying the directory manually.

- **Separate recipe packages** — Extract bundled recipes (currently `recipes/blog/`) into standalone Composer packages (e.g. `drupal-quick/recipe-blog`). Update the registry to remove `bundled: true` and point to real GitHub URLs. The `dq-install` script already handles the VCS registration and `composer require` flow for external recipes.

- **Fix external recipe path resolution** — When the first non-bundled external recipe ships, update `resolvePath()` in `DrupalQuickCommands.php` to return an absolute path for external entries. The current passthrough returns a path relative to the project root, which does not resolve correctly from Drush's working directory (`DRUPAL_ROOT`).

- **Build out the recipe library** — Add recipes beyond the blog POC. Each recipe should ship a `theme-assets/` directory with templates and preprocess includes where relevant.

- **Skin discovery for dq-init** — Once the starterkit is a separate package, update the interactive wizard to read available skins from the installed package metadata rather than from this package's `starterkits/skins/` directory.
