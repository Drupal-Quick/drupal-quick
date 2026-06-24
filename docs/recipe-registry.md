# Recipe registry — design & roadmap

How `config.dq.yml`, the recipe packages, and the registry fit together — and
where the registry is headed. This is a design note, not a spec.

---

## What the registry is for

The registry (`templates/recipe-registry.json`) is a **curated catalog** that
maps a friendly short key to the Composer package that provides it:

```json
{
  "blog": {
    "label": "Blog — Keywords taxonomy + entity reference field on Article",
    "package": "drupal-quick/recipe-blog",
    "url": "https://github.com/Drupal-Quick/recipe-blog"
  }
}
```

Its irreducible job is to answer, **before anything is installed**, two
questions: "what recipes can I pick?" (the `dq-init` interactive menu reads the
`label`s) and "which package + repo provides this key?" (so `dq-install` can
`composer require` it). Everything else is derived.

## How an entry flows through the tools

1. **`config.dq.yml`** lists recipe entries under `recipes:`.
2. **`dq-install`** resolves each entry to a package, registers its VCS repo
   (unless a local path repo already provides it), and `composer require`s it.
   `core-recipe-unpack` then unpacks it to `recipes/<package-short-name>/`.
3. **`dq:scaffold`** resolves each entry to that unpacked path, applies it with
   `drush recipe`, and injects any `theme-assets/` into the generated theme.

A recipe entry can be any of three forms:

```yaml
recipes:
  - "core/recipes/standard"                                  # core/contrib path (passthrough)
  - "blog"                                                   # registry key
  - { package: "you/recipe-x", url: "https://…/recipe-x" }   # inline package spec
```

## What is derived (not stored)

To keep the registry from drifting out of sync with reality, two formerly-stored
fields are now computed at runtime:

- **Unpacked path** — always `recipes/<package-short-name>`
  (`resolvePath()` in `ScaffoldCommand`), so it can never disagree with where
  `core-recipe-unpack` actually placed the recipe.
- **Theme assets** — `copyThemeAssets()` simply injects a recipe's
  `theme-assets/` directory if it exists and no-ops otherwise, so there is no
  `theme_assets` flag to get wrong.

This leaves only `package`, `url`, and a human `label` to maintain — and the
inline-spec form lets a one-off recipe skip the registry entirely.

## Adding a recipe today

1. Publish the recipe as a `type: drupal-recipe` Composer package (mirror
   [`recipe-blog`](https://github.com/Drupal-Quick/recipe-blog); see the
   `dq-add-recipe` skill).
2. Either add `{ key: { package, url, label } }` to the registry (to make it a
   first-class catalog option), **or** reference it inline in `config.dq.yml`
   with no registry edit.

---

## Roadmap — toward a self-generating catalog

The remaining manual step is keeping the registry's package list in step with
the actual recipe repos. The intended evolution:

1. **Self-describing packages.** Each recipe package declares its catalog
   metadata in its own `composer.json` `extra.dq` (`key`, `label`) — exactly as
   `dq_starterkit` already declares its `skins`. The package becomes the single
   source of truth.
2. **A generator, not a hand-edited file.** A `drush dq:registry:build` command
   (or a CI job) scans the recipe sources — the `Drupal-Quick` GitHub org via
   the API, or a tiny `sources:` list — reads each repo's `extra.dq`, and
   regenerates `recipe-registry.json`. Adding a recipe becomes: create the repo
   with the right metadata; the catalog updates itself.

### Rejected: pre/post-commit hook generation

Generating the registry from a Git hook on the `drupal-quick` repo was
considered and **rejected**:

- **Wrong trigger.** The registry depends on *other* repos, not on
  `drupal-quick`'s own commits, so a commit hook fires at the wrong moment and
  misses changes that happen in the recipe repos.
- **Fragile.** Fetching remote repos during a commit is slow and fails offline,
  turning every commit into a network operation.

The same goal is better served by **runtime derivation** (no generated artifact
to go stale — the most robust option, already in place for `path`/`theme_assets`)
or an **on-demand/CI generator** that runs when recipes actually change.

### TL;DR

- **Done (short term):** slim registry to `package`/`url`/`label`, derive
  `path` + `theme_assets`, allow inline package specs.
- **Next (medium term):** self-describing `extra.dq` packages + a generator
  command/CI — if/when zero-touch recipe discovery is worth the tooling.
- **Skip:** commit-hook generation (wrong trigger, fragile).
