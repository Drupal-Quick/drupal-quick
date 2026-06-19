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

### Running the build with DDEV

The same flow works inside [DDEV](https://ddev.com) — run every command through
`ddev` so it executes in the web container, which already provides PHP, Composer,
a database, and Node/npm for the theme build.

```bash
# 1. Create and start the DDEV project
mkdir my-site && cd my-site
ddev config --project-type=drupal --docroot=web --nodejs-version=20
ddev start
ddev composer create-project drupal/recommended-project .

# 2. Add this package. Until a tagged release is published on Packagist,
#    register the repository and require the dev branch explicitly.
ddev composer config repositories.drupal-quick vcs https://github.com/Drupal-Quick/drupal-quick
ddev composer require "drupal-quick/drupal-quick:dev-main"

# 3. Generate config.dq.yml (use -- so flags pass through to the script)
ddev composer exec -- dq-init            # add: -- dq-init --interactive --ddev

# 4. Edit config.dq.yml, then install any recipe packages
ddev composer exec -- dq-install

# 5. Run the scaffold (installs Drupal, generates theme, applies recipes, builds assets)
ddev drush dq:scaffold
```

Clean up the same way:

```bash
ddev drush dq:cleanup
```

Notes:

- `--nodejs-version=20` ensures the web container has a recent Node for the Vite
  build. Set `theme.build: false` in `config.dq.yml` to skip the npm build.
- `generate-theme` is invoked inside the container automatically by `dq:scaffold`;
  no extra step is needed.

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

- A custom theme at `web/themes/custom/{machine_name}/`, generated from the bundled `dq_starterkit` via Drupal core's `generate-theme` command and built with Vite and Tailwind CSS v4
- A chosen design skin baked into the theme as a CSS token layer
- CSS custom properties from `theme_design` written into the theme
- Recipe-specific Twig templates and PHP preprocess includes merged into the theme
- All recipes applied in declared order against a minimal Drupal install

---

## To do

- **Separate starterkit package** — Extract `dq_starterkit` into its own Composer package of type `drupal-theme` (`drupal-quick/dq-starterkit`). Today the scaffold already delegates theme generation to Drupal core's `generate-theme` command, but because this package installs outside the web root it first stages the starterkit into `web/themes/` so theme discovery can find it, then removes the copy. Shipping `dq_starterkit` as a `drupal-theme` package would place it at `web/themes/contrib/dq_starterkit/` where Drupal discovers it natively, letting the scaffold point `generate-theme` straight at the installed theme and drop the staging step.

- **Separate recipe packages** — Extract bundled recipes (currently `recipes/blog/`) into standalone Composer packages (e.g. `drupal-quick/recipe-blog`). Update the registry to remove `bundled: true` and point to real GitHub URLs. The `dq-install` script already handles the VCS registration and `composer require` flow for external recipes.

- **Fix external recipe path resolution** — When the first non-bundled external recipe ships, update `resolvePath()` in `DrupalQuickCommands.php` to return an absolute path for external entries. The current passthrough returns a path relative to the project root (e.g. `vendor/drupal-quick/recipe-blog`), which is fragile when handed to the `drush recipe` subprocess; bundled recipes already resolve to absolute paths, and external ones should too (build them from the project root, as `drupalRoot()` does for the web root).

- **Build out the recipe library** — Add recipes beyond the blog POC. Each recipe should ship a `theme-assets/` directory with templates and preprocess includes where relevant.

- **Skin discovery for dq-init** — Once the starterkit is a separate package, update the interactive wizard to read available skins from the installed package metadata rather than from this package's `starterkits/skins/` directory.

- **User-extensible starterkits and recipes** — Let users supply their own starterkit themes and recipes the same way the first-party ones work, without editing `vendor/`: merge the registry from the built-in file, a `config.dq.yml` `sources:` section, and self-describing packages (`composer.json` `extra`); un-gate theme-asset injection from the registry; make the starterkit selectable in config; and ship skeleton templates plus `dq-init` config additions. Full design, prerequisites, and suggested phasing in [docs/extensibility.md](docs/extensibility.md).

- **Static site export and deploy** — Ship the finished site as static HTML for a fast, footprint-free deploy. A `static:` block in `config.dq.yml` drives `drush dq:static`, which installs [Tome](https://tome.fyi), exports the site to `html/`, persists its settings to Drupal config (so they survive `dq:cleanup`), and writes a deploy config for the chosen target (Netlify / GitHub Pages). Currently a minimal prototype; rationale (config + command now, recipe later), caveats, and phasing in [docs/static-deploy.md](docs/static-deploy.md).
