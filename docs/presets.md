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
presets/corporate/
├── preset.css                      # the @theme tokens
├── extra.css                       # optional: @font-face / extra CSS
└── fonts/                          # optional: self-hosted font files
```

The two shipped presets (`minimal`, `corporate`) are the simple form. `minimal`
is the **default fallback**.

## The one hard constraint

**Tailwind v4 compiles `@theme` at build time.** Utility classes (`bg-primary`,
`font-sans`) bake the token values when `npm run build` runs. So a preset cannot
be a pure runtime "reference" — **changing a preset always requires a rebuild.**
That single fact drives the whole design below.

## Where the logic lives

Applying a preset is a Vite/Tailwind build concern, and it must keep working
**after Quick has removed itself** (`dq:cleanup`). So the apply logic
lives in the **theme**, not in a Drush command:

- **`npm run preset [<name>]`** (`scripts/preset.mjs`, pure Node, no deps) is the
  single source of truth. It writes the chosen preset's tokens into the
  `dq:preset` block of `src/main.css`, layers `presets/overrides.css`, copies any
  preset assets, then rebuilds. Re-runnable forever.
- **`dq:scaffold`** does the *initial* apply by simply calling that script
  (`npm run preset -- <name>`); it never re-implements token logic. Its only
  preset-specific job is translating `config.dq.yml`'s `theme_design` into the
  persisted `presets/overrides.css` (via `designTokenName()`).

This is the resolution of "baked-in vs. changeable": **initial apply by
Quick, ongoing changeability by the theme.**

## Discovery (no registry needed)

Unlike recipes, every preset lives inside the *one already-installed* starterkit
package — there is **no pre-install enumeration problem**, so there is **no
registry/generator** (the recipe-registry machinery does not apply here).

- The starterkit's `package.json` declares `dq.presets` (the list) and
  `dq.defaultPreset` (the fallback). `package.json` is the theme's manifest and
  survives `generate-theme`, so both `dq:scaffold` (for the interactive menu, via
  `discoverPresets()`) and `npm run preset` (for the fallback) read it.
- Fallback chain when no preset is named: `dq.defaultPreset` → `minimal` → first
  preset found in `presets/`. Resilient by default — an unset or unknown preset
  never breaks the build.

## Token layering

A preset is a **complete** token set (each preset re-states the reading-surface
tokens, so a preset *may* change them). Resolution, written into the `dq:preset`
block:

```
chosen preset  ←  presets/overrides.css (theme_design from config.dq.yml)
```

`overrides.css` persists, so it survives re-skinning: `npm run preset corporate`
keeps the user's `theme_design` tweaks on top of the new preset.

## Assets (fonts, extra CSS)

The directory form lets a preset ship more than tokens. On apply, the script:

1. copies `presets/<name>/fonts/*` → `src/fonts/` (self-hosted — keeps the Tome
   static export self-contained, no external font requests), and
2. injects `presets/<name>/extra.css` (e.g. `@font-face`) into a managed
   `dq:preset-extra` block in `main.css`, clearing it when switching back to a
   token-only preset.

## Making / importing a preset

Copy `presets/minimal.css` to `presets/<name>.css` and edit, or create
`presets/<name>/preset.css` (+ `fonts/`, `extra.css`) for the rich form. Add the
name to `package.json` `dq.presets` (optional — `presets/` is also scanned), then
`npm run preset <name>`.

## Summary

- Presets are theme-owned, self-contained token sets; discovery is just reading
  the installed starterkit's `package.json` — **no registry**.
- Switching is a build operation (`npm run preset`), inherent to Tailwind v4.
- The apply script is the single source of truth and survives Quick's
  removal; `dq:scaffold` calls it for the initial apply.
- A resilient default fallback (`minimal`) means a missing/unknown preset never
  breaks scaffolding.
