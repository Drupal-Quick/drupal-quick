---
name: dq-conventions
description: Read BEFORE editing any Quick starterkit, recipe, theme asset, or Drush command. Encodes the scaffold flow, the STARTERKIT token, recipe behaviour as OOP-hook submodules, Vite/Tailwind wiring, and the light-footprint rules that are easy to get wrong.
allowed-tools: Read, Grep, Glob
---

# Quick conventions

Quick is a one-shot scaffolding package: it provisions a Drupal site,
generates a custom theme, applies recipes, builds assets, then deletes itself.
Optimize for the **lightest possible footprint** and stay **Drupal-native**
wherever practical.

## The scaffold flow

Three steps (run each through `ddev` under DDEV):

1. `composer exec dq-init` — writes `config.dq.yml` (add `--interactive`, `--ddev`).
2. `composer exec dq-install` — resolves recipes from `templates/recipe-registry.json`.
3. `drush dq:scaffold` — installs Drupal, runs `generate-theme`, applies recipes, builds assets.

Teardown: `drush dq:cleanup` (archives `config.dq.yml`) or `--purge` (deletes it).
Static export: `drush dq:static`; publish: `drush dq:deploy`. Commands live in
`src/Drush/Commands/` (one class per command: `ScaffoldCommand`,
`CleanupCommand`, `StaticExportCommand`, `DeployCommand`; shared logic in the
`DrupalQuickHelpers` trait).

## The STARTERKIT token (theme assets only)

The starterkit is `dq_starterkit`. Renames during scaffold:

- `drupal generate-theme` rewrites `dq_starterkit` → the new theme machine name
  throughout the **starterkit** files.
- `dq:scaffold` copies recipe `theme-assets/` and does a literal
  `str_replace('STARTERKIT', $themeName, …)` on file **contents and names**.

`theme-assets/` is **templates only** (Twig). Use the `STARTERKIT` token only if
a template must reference the theme machine name (rare). In the **starterkit**
itself, write `dq_starterkit_*` (generate-theme renames it). Recipe **behaviour**
(preprocess + JSON-LD) is **not** a theme asset and uses no token — see below.

## Recipe behaviour: OOP hooks in submodules

Recipe preprocess/JSON-LD ships as a **submodule** in the recipe's `module/`
directory. `dq:scaffold` assembles each one under the umbrella module at
`modules/custom/dq_hooks/modules/<name>/`, and the recipe's `install:` enables
it. Behaviour is native OOP — a class in `src/Hook/` with `#[Hook]` methods:

```php
namespace Drupal\dq_blog\Hook;
use Drupal\Core\Hook\Attribute\Hook;

final class BlogHooks {
  #[Hook('preprocess_node')]
  public function preprocessNode(array &$variables): void {
    if ($variables['node']->bundle() !== 'article') {
      return;                       // base hook fires for every bundle — guard.
    }
    // ...
  }
}
```

**Why submodules:** a theme (one extension) may implement a given preprocess
hook **only once** — `ThemeManager::invoke()` throws on a second — so multiple
recipes can't each add one inside the theme. A submodule is a **separate
extension**, so their same-hook `#[Hook]` methods all stack with no conflict and
no dispatcher.

- Module namespace = the **module** machine name (`Drupal\dq_blog\Hook`), not the
  theme — so no `STARTERKIT` token in module PHP.
- Recipe modules use **module** OOP preprocess hooks (introduced in Drupal
  11.2, backported to 11.1.8). The **stack floor is `^11.3`** — the tested
  range is 11.3 and 11.4 (the scaffold auto-detects 11.4's consolidated `dr`
  CLI and its stricter recipe validation is accounted for).
- The **starterkit** keeps its own presentation hooks procedural in `.theme`
  (one impl each, no conflict); it does **not** use OOP theme hooks (those are
  11.3+, and we don't need them).

## Theme build (Vite + Tailwind v4)

- Source in the starterkit package's `src/`; build emits `dist/main.css|js`.
- CSS is **inlined** into `html.html.twig` (`preprocess_html`), and the linked
  copy is removed in `hook_css_alter()` — don't re-add a `<link>`.
- JS assets in `*.libraries.yml` use `attributes: { type: module }`, NOT
  `type: module` (Drupal reads `type` as the asset kind and rejects `module`).
- Vite dev/HMR: `vite.config.js` writes a `.vite-dev` marker;
  `hook_page_attachments_alter()` swaps `dist/` assets for the live dev server.
  Never commit a `.vite-dev` file.
- Design tokens live in a **preset** (`presets/<name>.css`); `npm run preset
  <name>` writes them into the entry `@theme` (`dq:preset` block) and rebuilds.
  Tailwind v4 compiles `@theme` at build time, so changing a preset needs a
  rebuild — there's no runtime swap. Available presets + the default are declared
  in `package.json` `dq.*` (the source of truth for discovery); a `prebuild` hook
  re-applies the active preset so a bare `npm run build` self-heals.
- Self-hosted fonts are pulled **on demand**: a directory preset's `fonts.json`
  pins a URL + `sha256`, fetched into `src/fonts/` (gitignored) at preset time —
  **never commit font binaries**. See `docs/presets.md`.
- **Content-scale contract:** presets define `--text-title`, `--text-meta`,
  `--spacing-flow` (+ `--spacing-row`); recipe templates use ONLY those
  utilities (`text-title`, `text-meta`, `gap-flow`, `py-row`) for type scale and
  inter-unit spacing — never raw `text-sm`/`gap-6`. That's how a preset's scale
  reaches recipe markup. See `docs/presets.md` § contract.

## Site composition (config.dq.yml → scaffold)

- **Recipe options = native recipe inputs.** Declared in the recipe's
  `recipe.yml` `input:` (typed, always defaulted), consumed in actions as
  `'${name}'`, set by users via `- name: <key>` / `options:` entries, passed by
  `dq:scaffold` as `--input=<recipe-dir>.<name>=<value>`. Inputs parameterize
  action *values* only — they cannot skip actions; conditionality lives in the
  scaffold. Substituted values arrive as strings. **`dq-install` writes each
  fetched recipe's options into config.dq.yml as a commented block under its
  entry** (in place; promotes a bare `- "key"` to `- name: "key"`; idempotent;
  prefixes `# ` so uncommenting is valid YAML). `--exclude-options` prints to
  the terminal instead.
- **Layout is baked at scaffold time, not a runtime setting.** The starterkit
  ships the default shell (`templates/includes/page-shell.html.twig`, the
  sidebar arrangement, embedded by both page templates) plus one file per
  alternative (`page-shell--<layout>.html.twig`). `dq:scaffold` copies the
  chosen variant over the shell and deletes the rest — afterwards the shell is
  ordinary Twig the user edits directly. No theme setting, no settings form,
  no runtime branch; only the chosen arrangement's classes get compiled.
- **Homepage composition:** recipes advertise placeable blocks in
  `composer.json` `extra.dq.recipe.blocks`; `homepage.blocks` entries
  (`"<recipe>/<block>"`, order = weight) become block config in the content
  region, `<front>`-only, and the front page moves to the dedicated always-empty
  `/home` view. Capabilities ship with the recipe; *placement* is scaffold-side
  selection. Omit `homepage:` → the recipes' own front page stands.
- The registry is a **generated cache** (`bin/dq-registry-build`) carrying
  label/package/url + `blocks` + an `options` summary of each recipe's inputs
  (so `dq-init --interactive` can ask before packages are fetched). Never
  hand-edit it.

## Light-footprint rules

- Prefer Drupal-native APIs over bespoke logic (e.g. resolve paths via the
  theme/file services, not `getcwd()`).
- Don't add a contrib module when a few lines of hand-built markup will do
  (see the module-free JSON-LD in `docs/structured-data.md`).
- Leave no "generated by Quick" markers in output.
- Keep recipes self-contained: each ships its own `config/`, `theme-assets/`
  (templates), and `module/` (behaviour).

## Where things live

This repo is **orchestrator-only**; the theme and recipes are separate packages.

- Starterkit theme: the `drupal-quick/dq_starterkit` package (own repo) —
  `.theme`, `templates/`, `src/`, `presets/`.
- Recipes: standalone `drupal-recipe` packages (own repos, e.g. `recipe-blog`) —
  `recipe.yml`, `config/`, `theme-assets/` templates, `module/` behaviour. At
  scaffold time recipes unpack to the consumer's `recipes/<name>/` and their
  `module/` is assembled under `modules/custom/dq_hooks/modules/`.
- Commands (this repo): `src/Drush/Commands/` (`ScaffoldCommand`,
  `CleanupCommand`, `StaticExportCommand`, `DeployCommand`; `DrupalQuickHelpers`
  trait). Standalone CLI scripts in `bin/` (`dq-init`, `dq-install`,
  `dq-registry-build`).
- **Pure logic lives in plain classes, not in the bins.** `src/Config/`
  (`RecipeEntry`, `RecipeOptions`, `PresetDiscovery`) and `src/Registry/`
  (`RegistryEntry`) hold the fiddly deterministic pieces; the bin scripts
  `require_once` them directly (no autoloader assumption) and stay thin.
  Unit-test any new logic of this kind in `tests/Unit/` — run with
  `composer test` (no Drupal needed; CI runs it on every push).
- Config/registry: `templates/config.dq.yml`, `templates/recipe-registry.json`.
- Design notes: `docs/extensibility.md`, `docs/static-deploy.md`, `docs/structured-data.md`.
