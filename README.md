# drupal-quick

A Composer package that scaffolds a new Drupal site from a single config file. Run it once to get a working site with a generated custom theme and your chosen recipes applied, then remove it — no long-term dependency on the project.

> **Work in progress.** The bundled theme and recipe are proofs of concept; the core workflow is functional.

---

## How it works

After creating your Drupal project (`composer create-project drupal/recommended-project my-site`), the build is three commands driven by `config.dq.yml`:

```bash
# Until tagged releases are on Packagist, register the package repos + allow dev:
composer config minimum-stability dev && composer config prefer-stable true
composer config repositories.drupal-quick vcs https://github.com/Drupal-Quick/drupal-quick
composer config repositories.dq_starterkit vcs https://github.com/Drupal-Quick/dq-starterkit
composer require "drupal-quick/drupal-quick:dev-main" drush/drush

composer exec dq-init        # write config.dq.yml (--interactive for a wizard)
# edit config.dq.yml
composer exec dq-install     # fetch any registry recipe packages
drush dq:scaffold            # install Drupal, generate theme, apply recipes, build assets
```

When the site is built, remove drupal-quick — the tool deletes its own code, leaving a self-contained project:

```bash
drush dq:cleanup             # archive config.dq.yml (commented) + remove the package
drush dq:cleanup --purge     # delete config.dq.yml instead of archiving
```

### With DDEV

Run every command through `ddev` so it executes in the web container (PHP, Composer, Node/npm, and a database all provided). For the full step-by-step walkthrough including static export and deploy, see [docs/workflow.md](docs/workflow.md).

```bash
mkdir my-site && cd my-site
ddev config --project-type=drupal --docroot=web --nodejs-version=20
ddev start
ddev composer create-project drupal/recommended-project .

# Until tagged releases are on Packagist, register the package repos + allow dev:
ddev composer config minimum-stability dev && ddev composer config prefer-stable true
ddev composer config repositories.drupal-quick vcs https://github.com/Drupal-Quick/drupal-quick
ddev composer config repositories.dq_starterkit vcs https://github.com/Drupal-Quick/dq-starterkit
ddev composer require "drupal-quick/drupal-quick:dev-main"

ddev composer exec -- dq-init     # add --interactive and/or --ddev after `--`
ddev composer exec -- dq-install
ddev drush dq:scaffold
```

`--nodejs-version=20` gives the container a recent Node for the Vite build; set `theme.build: false` to skip it. Pass `--ddev` to `dq-init` to drop in DDEV deploy-credential templates for `dq:static --deploy`.

---

## config.dq.yml

```yaml
site:
  name: "My Site"
  admin_user: "admin"
  # Omit admin_pass to have a strong password generated and shown once.

theme:
  machine_name: "my_theme"
  title: "My Theme"
  preset: "minimal"      # a design preset from the starterkit (presets/)
  build: true            # false to skip the npm build

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

static:                  # optional, used by `drush dq:static`
  target: "netlify"      # netlify | github | none
  uri: "https://example.com"
```

---

## What you get

- A custom theme at `web/themes/custom/{machine_name}/`, generated from `dq_starterkit` and built with Vite + Tailwind CSS v4.
- Your chosen design **preset** (colors, type, optional fonts) plus `theme_design` overrides applied to the theme — swap it anytime with `npm run preset <name>`.
- Recipe templates merged into the theme and recipe behaviour assembled as native OOP-hook submodules; all recipes applied in order against a minimal install.
- Module-free [Schema.org JSON-LD](docs/structured-data.md) on content pages (`BlogPosting` for articles, `WebPage` for pages), built from each node's own fields.

---

## Static export

`drush dq:static` installs [Tome](https://tome.fyi), exports the site to static HTML in `html/`, and writes a deploy config for the chosen `static.target` (Netlify or GitHub Pages). Settings persist to Drupal config so they survive `dq:cleanup`. Add `--deploy` to push to Netlify (needs CLI auth). Full workflow and caveats in [docs/static-deploy.md](docs/static-deploy.md).

---

## Requirements

PHP 8.1+ · Drupal 11.1.8+ (recipe modules use module preprocess OOP hooks) · Drush 12.5+ · Node.js/npm (for the theme build)

---

## Roadmap

- **Build out the recipe library** beyond the blog proof of concept.
- **User-extensible starterkits and recipes** without editing `vendor/` — design in [docs/extensibility.md](docs/extensibility.md).
- **Harden static export** and consider a recipe form — see [docs/static-deploy.md](docs/static-deploy.md).
