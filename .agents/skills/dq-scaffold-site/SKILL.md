---
name: dq-scaffold-site
description: Use when provisioning a new Drupal site with Quick from scratch — the dq-init → dq-install → drush dq:scaffold flow, on the host or inside DDEV. Covers config.dq.yml, verification, and teardown.
---

# Scaffold a Quick site

Provision a working Drupal site from an empty project. Prefer **DDEV** so PHP,
Composer, the database, and Node/npm all come from the container.

## 1. Create the project

DDEV (recommended):

```bash
mkdir my-site && cd my-site
ddev config --project-type=drupal --docroot=web --nodejs-version=20
ddev start
ddev composer create-project drupal/recommended-project .
ddev composer config repositories.drupal-quick vcs https://github.com/Drupal-Quick/drupal-quick
ddev composer require "drupal-quick/drupal-quick:dev-main" drush/drush
```

Host equivalent: same `composer` commands without the `ddev` prefix.

## 2. Generate config

```bash
ddev composer exec -- dq-init            # add: --interactive --ddev
```

Edit `config.dq.yml`: choose the starterkit preset, set `theme_design` tokens,
and list recipes under `recipes:` (each must exist in
`templates/recipe-registry.json`).

## 3. Install recipe packages

```bash
ddev composer exec -- dq-install
```

Bundled recipes (e.g. `blog`) need nothing extra; external ones are
`composer require`d here.

## 4. Scaffold

```bash
ddev drush dq:scaffold
```

This installs Drupal, runs `generate-theme` (renaming `dq_starterkit` → your
theme machine name), assembles each recipe's `module/` under the umbrella module
(`modules/custom/dq_hooks/modules/`), applies recipes in declared order (their
`install:` enables those submodules), injects each recipe's `theme-assets/`
templates, and builds the theme assets.

## 5. Verify

```bash
ddev drush status                      # site installed, DB connected
ddev drush config:get system.theme default
ls web/themes/custom/*/dist/           # main.css + main.js built
ddev launch                            # open the site
```

Check a content page renders, the inlined CSS is present, and (if the blog
recipe was applied) articles show keywords + JSON-LD.

## 6. Teardown when done

```bash
ddev drush dq:cleanup                   # archive config.dq.yml, remove the package
ddev drush dq:cleanup --purge           # delete config.dq.yml for zero trace
```

## Gotchas

- Run **every** command through `ddev` under DDEV, including `composer exec` —
  use `--` so flags reach the script: `ddev composer exec -- dq-init --interactive`.
- If the theme JS 404s or throws "Invalid JS asset type", check
  `*.libraries.yml` uses `attributes: { type: module }`.
- Turn off CSS/JS aggregation while iterating on the theme; the Vite dev server
  (`ddev npm run dev`) serves live assets via the `.vite-dev` marker.
- For the full convention set and how recipe behaviour ships as OOP-hook
  submodules, read the `dq-conventions` skill first.
