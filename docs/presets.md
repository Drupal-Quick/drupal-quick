# Design presets — design

How a "preset" (a named bundle of design tokens, type, and optional assets) is
chosen, applied, and changed. A preset is the starterkit's analogue of a theme
"skin", renamed to avoid collision with the Drupal *theme* and Tailwind's
`@theme`.

---

## What a preset is

A complete set of Tailwind v4 design tokens — an `@theme` block of CSS custom
properties (colors, `--font-sans`, spacing, the reading surface). Two forms:

```
presets/minimal.css                 # simple: just the @theme tokens
presets/geometric/
├── preset.css                      # the @theme tokens
└── fonts.json                      # optional: self-hosted webfonts (pinned URL + sha256)
```

The shipped presets are `minimal`, `corporate` (both simple form), and
`geometric` (directory form, self-hosted font). `minimal` is the **default
fallback**.

## The one hard constraint

**Tailwind v4 compiles `@theme` at build time.** Utility classes (`bg-primary`,
`font-sans`) bake the token values when `npm run build` runs. So a preset cannot
be a pure runtime "reference" — **changing a preset always requires a rebuild.**
That single fact drives the whole design below.

## Where the logic lives

Applying a preset is a Vite/Tailwind build concern, and it must keep working
**after Quick has removed itself** (`dq:cleanup`). So the apply logic
lives in the **theme**, not in a Drush command:

- **`npm run preset [<name>]`** (`scripts/preset.mjs`, pure Node — global `fetch`
  + `node:crypto`, no deps) is the single source of truth. It writes the chosen
  preset's tokens into the `dq:preset` block of `src/main.css`, layers
  `presets/overrides.css`, fetches any preset fonts on demand (see **Assets**
  below), records the active preset in `package.json`, then rebuilds. Re-runnable
  forever.
- **`dq:scaffold`** does the *initial* apply by simply calling that script
  (`npm run preset -- <name>`); it never re-implements token logic. Its only
  preset-specific job is translating `config.dq.yml`'s `theme_design` into the
  persisted `presets/overrides.css` (via `designTokenName()`).

This is the resolution of "baked-in vs. changeable": **initial apply by
Quick, ongoing changeability by the theme.**

### Self-healing builds

Because fonts and `dist/` are gitignored (see **Assets**), a bare `npm run build`
on a fresh clone would otherwise be missing them. A **`prebuild`** hook
(`node scripts/preset.mjs --sync`) runs before every build and re-applies the
**active** preset — re-fetching fonts and regenerating `main.css` — so `npm run
build` alone always produces a correct, self-contained result. The active preset
is persisted to `package.json` `dq.activePreset` (written whenever `npm run
preset` runs), so it is committed with the site and reproduced on any clone.

## Discovery (no registry needed)

Unlike recipes, every preset lives inside the *one already-installed* starterkit
package — there is **no pre-install enumeration problem**, so there is **no
registry/generator** (the recipe-registry machinery does not apply here).

- The starterkit's `package.json` declares `dq.presets` (the list) and
  `dq.defaultPreset` (the fallback) — the **single source of truth**.
  `package.json` is the theme's manifest and survives `generate-theme`, so
  `dq:scaffold` (interactive menu, via `discoverPresets()`), `bin/dq-init`, and
  `npm run preset` all read the same block. It travels intact, so there is no
  filesystem scan; a hardcoded `['minimal', 'corporate']` is the last resort if
  the manifest can't be read.
- Fallback chain when no preset is named: `dq.defaultPreset` → `minimal`. If a
  *named* preset has no token file, the script warns and falls back to `minimal`
  (or the first available). Resilient by default — an unset or unknown preset
  never breaks the build.

## Token layering

A preset is a **complete** token set (each preset re-states the reading-surface
tokens, so a preset *may* change them). Resolution, written into the `dq:preset`
block:

```
chosen preset  ←  presets/overrides.css (theme_design from config.dq.yml)
```

`overrides.css` persists, so it survives re-skinning: `npm run preset corporate`
keeps the user's `theme_design` tweaks on top of the new preset. In a generated
site it is **committed** (the starterkit gitignores it only for its own repo
hygiene, and deliberately withholds its `.gitignore` from `generate-theme` so
the site's root rules govern) — it cannot be regenerated once `dq:cleanup`
removes config.dq.yml.

## The content-scale contract

Recipes ship their own templates, yet a preset's type scale and negative space
must reach them — an airy preset must make *article titles and project titles
alike* larger and looser without either recipe knowing which preset runs. The
mechanism is a small **contract vocabulary** on top of the color/font tokens:

| Token | Utility | Meaning |
| --- | --- | --- |
| `--text-title` (+ `--line-height`) | `text-title` | content-item titles in listings |
| `--text-meta` (+ `--line-height`) | `text-meta` | dates, captions, keyword lists |
| `--spacing-flow` | `gap-flow`, `py-flow`, … | space between content units |
| `--spacing-row` | `py-row` | vertical rhythm of a list row |

Two rules make it work:

- **Preset authors:** every preset MUST define the full contract (it is part of
  "a preset is a complete token set"). Differentiate through it — `geometric`
  ships larger `--text-title` and a wider `--spacing-flow`.
- **Recipe authors:** templates MUST use the contract utilities for type scale
  and inter-unit spacing — never raw `text-sm` / `gap-6`. Colors already flow
  through `text-ink` / `text-muted` / `bg-rule` etc. Anything hardcoded is
  invisible to presets.

`theme_design` can override contract tokens too: a key like `text_title` maps
to `--text-title` via the generic kebab rule, so
`theme_design: { text_title: "1.25rem" }` works with no special casing.

## Assets (self-hosted fonts, on demand)

The directory form lets a preset self-host webfonts **without committing any
binary**. `presets/<name>/fonts.json` pins each font's download URL (a Google
Fonts / gstatic `woff2`) and `sha256`. On apply, the script:

1. **downloads** each font into `src/fonts/` (gitignored) only when that preset
   is applied, **verifying** it against the pinned `sha256` — reusing any cached
   copy, so a repeat apply doesn't re-fetch;
2. **generates** the `@font-face` rules from the manifest into a managed
   `dq:preset-extra` block in `main.css`, clearing it when switching back to a
   token-only preset; and
3. **prunes** any files in `src/fonts/` the active preset no longer needs.

The font is bundled into `dist/` by Vite, so the built site is self-contained
(no external font request at render time — good for the Tome static export). The
trade-off: a first build needs **network access**, on the assumption you build
right after cloning. Nothing font-shaped ships in the repo, and an unused
directory-preset costs only a little CSS + JSON.

```json
// presets/geometric/fonts.json
{ "fonts": [{
  "family": "Geom", "weight": 400, "style": "normal", "display": "swap",
  "file": "Geom-latin.woff2",
  "url": "https://fonts.gstatic.com/s/geom/v1/…woff2",
  "sha256": "ae1748bec04bf4e0…"
}] }
```

Grab the `woff2` URL + hash from [google-webfonts-helper](https://gwfh.mranftl.com).

## Making / importing a preset

Copy `presets/minimal.css` to `presets/<name>.css` and edit, or create
`presets/<name>/preset.css` (+ `fonts.json`) for the self-hosted-font form. Add
the name to `package.json` `dq.presets` (that list is authoritative for
discovery), then `npm run preset <name>`.

## Summary

- Presets are theme-owned, self-contained token sets; discovery is just reading
  the installed starterkit's `package.json` — **no registry**.
- Switching is a build operation (`npm run preset`), inherent to Tailwind v4.
- The apply script is the single source of truth and survives Quick's
  removal; `dq:scaffold` calls it for the initial apply.
- A resilient default fallback (`minimal`) means a missing/unknown preset never
  breaks scaffolding.
