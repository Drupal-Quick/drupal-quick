# Extensibility: user-supplied starterkits and recipes

This document captures the design for letting **users** extend Quick with
their own starterkit themes and recipes — the same way the first-party
`dq_starterkit` and bundled recipes work — without editing anything inside
`vendor/`.

It is a design note, not yet implemented. It also lists the groundwork that must
land **before** any of this work begins.

---

## Goal

A user should be able to publish a recipe or starterkit as a normal Composer
package and have Quick pick it up — ideally just by `composer require`-ing
it and naming it in `config.dq.yml`, with no edits to Quick's own files.

---

## Prerequisites — ✅ complete

> **Status:** done. The starterkit (`drupal-quick/dq_starterkit`) and the recipes
> (`recipe-blog`, `recipe-project`) now live in their own repos/packages, and
> external recipe path resolution is fixed. The steps below are kept as the
> record of that work; the design that follows builds on it.

This design assumes the theme and the non-bundled recipes already live in their
own repositories/packages. The following were the prerequisites:

### 1. Extract `dq_starterkit` into its own `drupal-theme` package

1. Create a new repository, e.g. `github.com/Drupal-Quick/dq-starterkit`.
2. Move `starterkits/dq_starterkit/*` into it as a `composer.json` of
   `"type": "drupal-theme"` named `drupal-quick/dq-starterkit`.
3. Keep the starter kit conventions already established: the
   `dq_starterkit.starterkit.yml` marker, a concrete `version:` in
   `dq_starterkit.info.yml` (so `generate-theme` runs non-interactively), and the
   machine-name token (`dq_starterkit`) used throughout for substitution.
4. Decide where the skins (`starterkits/skins/*.css`) live — most naturally they
   move into the starterkit package too, so skin discovery can read them from the
   installed theme rather than from Quick.
5. Tag a release (e.g. `1.0.0`) and publish to Packagist, or document the VCS
   install. A tag avoids the `dev-main` constraint consumers currently need.
6. In Quick, add the starterkit as a `require` (or document requiring it).
   Composer's installer places it at `web/themes/contrib/dq_starterkit/`, where
   Drupal discovers it natively — letting `dq:scaffold` drop the temporary
   web-root **staging** step and point `generate-theme` straight at it.

### 2. Extract the bundled recipe(s) into their own `drupal-recipe` packages

1. Create a repository per recipe, e.g. `github.com/Drupal-Quick/recipe-blog`.
2. Move `recipes/blog/*` into it as a `composer.json` of
   `"type": "drupal-recipe"` named `drupal-quick/recipe-blog`, keeping
   `recipe.yml`, `config/`, and `theme-assets/`.
3. Tag a release and publish (or document the VCS URL).
4. Update `templates/recipe-registry.json`: remove `"bundled": true` and set the
   real `url`. `dq-install` already handles VCS registration + `composer require`
   for non-bundled entries.

### 3. Fix external recipe path resolution

When the first non-bundled recipe ships, update `resolvePath()` in
`ScaffoldCommand.php` so external entries return an **absolute** path (built
from the project root) instead of a project-root-relative one. Bundled recipes
already resolve to absolute paths; external ones must too, or the path handed to
the `drush recipe` subprocess is fragile.

Once these three are done, Quick no longer *ships* a theme or recipes — it
*orchestrates* installed ones. That is the foundation the rest of this design
builds on.

---

## What already works today

Recipes that are **not** in the registry are passed straight through to
`drush recipe` as literal paths (`resolvePath()` returns the key unchanged). So a
user can already install their own recipe package with `composer require` and
reference its path in `config.dq.yml` — no registry entry required.

### The gap

Two conveniences are currently gated on registry membership:

1. **Auto-install** — `dq-install` only registers a VCS repo and runs
   `composer require` for entries it finds in the registry.
2. **Theme-asset injection** — `copyThemeAssets()` only runs when
   `isset($registry[$recipe]) && registry[$recipe]['theme_assets']`. A user's
   recipe that ships a `theme-assets/` directory is **silently ignored** unless
   it is in the registry.

So a custom recipe technically works, but it is a second-class citizen.

---

## The registry: from a static file to a merged, self-describing system

The root problem is that `templates/recipe-registry.json` lives **inside** the
package. Users cannot add to it without editing `vendor/`, which is overwritten
on update. Two complementary mechanisms solve this.

### (A) A user registry section in `config.dq.yml`

The simple, transparent escape hatch:

```yaml
sources:
  my_recipe:
    package: "acme/recipe-events"
    url: "https://github.com/acme/recipe-events"
    theme_assets: true
recipes:
  - "my_recipe"
```

`dq-install` and `dq:scaffold` would load the built-in registry **and** merge
these entries on top. Minimal change; users own their own entries.

### (B) Self-describing packages via `composer.json` `extra`

The more Composer/Drupal-native approach — it mirrors how Drush commands and
Drupal modules self-register:

```json
{
  "type": "drupal-recipe",
  "extra": {
    "drupal-quick": { "recipe": { "label": "Events", "theme_assets": true } }
  }
}
```

Quick discovers these by scanning `vendor/composer/installed.json`, which
is **readable without a service container** — so it fits the constraint
`dq:scaffold` already operates under (no reliable container; see why we use
`Drush::bootstrapManager()->getRoot()` instead of `\Drupal::root()`). With this,
no central registry entry is needed at all: the package *is* its own entry.

### Install-order nuance

Mechanism (B) can only see packages that are **already installed**. If you want
Quick to auto-install a recipe from a short key (today's external flow),
it needs the `package` + `url` *before* installation — which only (A) or the
built-in registry can provide. The clean split:

- **User runs `composer require acme/recipe-events` themselves** → (B)
  auto-discovers it for application + theme-assets. Zero registry config. This is
  the truest "extend the same way."
- **User wants Quick to fetch it from a key** → needs (A) or the curated
  built-in registry.

**Recommendation:** lean on **(B) as the primary path** and demote the built-in
registry to a curated, first-party convenience. That removes the central file as
a bottleneck entirely.

---

## Themes / starterkits

Same shape as recipes, with two specific changes:

1. **Make the starterkit selectable.** `dq:scaffold` currently hardcodes
   `$starterkitId = 'dq_starterkit'`. Expose it in config:

   ```yaml
   theme:
     starterkit: "acme_starterkit"   # any installed theme with a .starterkit.yml
   ```

   `generate-theme` already accepts `--starterkit`, so this is a near-trivial
   wiring change. Once starterkits are installed `drupal-theme` packages (see
   prerequisites), user starterkits land in `web/themes/contrib/` and are
   discoverable identically — dropping even the staging step.

2. **Skin discovery** should read from the selected starterkit's package rather
   than Quick's own `starterkits/skins/` directory.

---

## Decouple theme-asset injection from the registry

Independent of everything above: change `copyThemeAssets()` to inject whenever
the resolved recipe path **contains** a `theme-assets/` directory, regardless of
registry membership. This single change makes every recipe — first-party or
user-supplied — behave consistently.

---

## Templates / generators to ship

- **Skeleton repos or a generator** for the two package types:
  - a recipe package — `recipe.yml` + optional `config/` + optional
    `theme-assets/`, plus the `composer.json` `type`/`extra` from (B);
  - a starterkit theme package — `*.starterkit.yml` + the conventions already
    established for `dq_starterkit`.
  Drupal core already provides `generate-theme` for *consuming* starterkits; a
  documented template repo (or a `drush dq:recipe-skeleton`) covers the
  *producing* side.
- **`config.dq.yml` template updates** — a commented `sources:` block and the
  `theme.starterkit` key, surfaced by `dq-init` (including the interactive
  wizard, which could list discovered starterkits/recipes from
  `installed.json`).
- **Optional `drush dq:register`** helper that writes a `sources:` entry for an
  already-installed package, so users do not hand-edit YAML.

---

## Suggested phasing

1. **Prerequisites** (theme package, recipe packages, external path fix) — see
   above.
2. **Un-gate theme-asset injection** from the registry (smallest, highest-value
   change; benefits first-party recipes immediately).
3. **Parameterize the starterkit** (`theme.starterkit` in config) and move skin
   discovery to the starterkit package.
4. **Merge registry sources** — add the `config.dq.yml` `sources:` section (A).
5. **Auto-discovery** via `composer.json` `extra` + `installed.json` (B); make it
   the primary path and demote the built-in registry to curated convenience.
6. **Templates / generators / `dq-init` config additions** to make authoring new
   packages turnkey.

None of this requires fighting the container constraint, since package discovery
reads `installed.json`. The biggest single unlock is **self-describing packages
via `composer.json extra`** — that is what turns "edit our central registry" into
"publish a package and it just works."
