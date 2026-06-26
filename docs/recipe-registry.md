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

1. Publish the recipe as a `type: drupal-recipe` Composer package with its
   catalog metadata in `extra.dq.recipe` (`key`, `label`) — mirror
   [`recipe-blog`](https://github.com/Drupal-Quick/recipe-blog); see the
   `dq-add-recipe` skill.
2. Regenerate the cache: `php bin/dq-registry-build` (or let CI run it). For a
   one-off you can skip the catalog and reference the recipe inline in
   `config.dq.yml` (`{ package, url }`) with no registry entry at all.

---

## The registry is a generated cache

The registry is no longer hand-edited — it is **regenerated** by
`bin/dq-registry-build` from self-describing packages. Two layers make this work,
and it helps to keep them distinct:

- **Metadata (solved by `extra.dq`).** Each recipe package declares its own
  catalog entry in `composer.json` — exactly as `dq_starterkit` declares its
  `skins`. The package is the single source of truth for its `key` + `label`;
  `package` and `url` are derived:

  ```json
  { "type": "drupal-recipe",
    "extra": { "dq": { "recipe": { "key": "blog", "label": "Blog — …" } } } }
  ```

- **Enumeration (still needs a source).** `extra.dq` can only be read *after* a
  package is fetched, so it cannot, by itself, tell you *which* packages exist
  before install. Something must enumerate the candidates first. `dq-registry-build`
  does this two ways (combined; local paths win on key collision):
  - `--org=Drupal-Quick` — enumerate a GitHub org via the API (the default),
  - `--path=DIR` — read a local recipe checkout (url from its git origin).

So `recipe-registry.json` is best understood as a **committed cache** of that
enumeration, keyed off one constant (the org name) — not a catalog to maintain by
hand. It is committed so `dq-init`/`dq-install` work offline and deterministically.

### When it regenerates

Because the registry **ships inside this package**, users only receive a refresh
when drupal-quick is released — so the sustainable trigger is **drupal-quick's own
CI** running `bin/dq-registry-build` at build/release time (the delivery moment),
with the script as the reusable engine. Regenerating from *recipe* repos instead
was rejected: it is N→1 cross-repo coupling and the freshness is illusory (still
gated on a drupal-quick release). The script needs no Drupal site, so the CI step
is trivial. To refresh by hand: `php bin/dq-registry-build`.

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

- **Done:** slim registry to `package`/`url`/`label`, derive `path` +
  `theme_assets`, allow inline package specs.
- **Done:** self-describing `extra.dq.recipe` packages + `bin/dq-registry-build`
  generating the registry cache from a GitHub org and/or local checkouts.
- **Next:** wire `bin/dq-registry-build` into drupal-quick's CI so the shipped
  cache refreshes at release time.
- **Later (optional):** if recipes move to Packagist, the catalog could be
  queried live at `dq-init` (no committed file) — at the cost of offline
  determinism. Revisit then; the `url` field becomes vestigial.
- **Skip:** commit-hook generation, and regenerating from *recipe*-repo triggers
  (wrong trigger / N→1 coupling; freshness gated on a drupal-quick release).
