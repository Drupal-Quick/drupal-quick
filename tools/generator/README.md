# config.dq.yml generator (POC)

A static form + live-preview page that composes a complete `config.dq.yml`,
ready to paste (or download) into a project before `drush dq:scaffold`. It
streamlines what `dq-init --interactive` asks and pre-answers what `dq-install`
would write back (commented recipe options, the block catalog).

Internal testing tool for now; the eventual home is quickthe.me.

## Stack

- Single static page: `index.html` + `app.js` + `catalog.js`. No build step.
- [Alpine.js](https://alpinejs.dev) (CDN) for reactivity, Tailwind v4 browser
  CDN for the form chrome. Needs network for the two CDN scripts.
- The preview pane is styled **only** by `--pv-*` CSS variables set from the
  selected preset's real token values (transcribed into `catalog.js` from
  `dq-starterkit/presets/*.css`), so switching preset/layout/overrides
  restyles the mock homepage with the actual design tokens.

## Running it

Copy (or symlink) this directory into any web-served docroot. For the smoke
site:

```bash
cp -R tools/generator ~/dev/dq-smoke/web/generator
# → https://dq-smoke.ddev.site/generator/
```

Opening `index.html` directly from disk also works (clipboard copy requires a
secure context, so prefer the served form).

## Maintenance contract

The YAML emitter in `app.js` reproduces the canonical output of three sources
and must be kept in sync with them (until the registry emits a shared JSON
contract both PHP and this page consume):

- `templates/config.dq.yml` — section comments, verbatim
- `src/Config/RecipeOptions::activeLines()` — option line format
- `src/Config/RecipeBlocks::commentedCatalog()` — the block catalog section

`catalog.js` hardcodes the recipe/preset metadata that `recipe-registry.json`
and the starterkit `package.json` provide at runtime; regenerate it by hand
when those change.

@todo Dummy content per recipe should eventually ship in the recipe itself
(a sample-content metadata block) rather than in `catalog.js`.
