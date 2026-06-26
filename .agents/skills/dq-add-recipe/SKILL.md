---
name: dq-add-recipe
description: Use when authoring a new drupal-quick recipe — a self-contained bundle of a Drupal recipe.yml, its config, optional theme templates, and a behaviour submodule. Covers the directory layout, config actions, the OOP-hook submodule pattern, and registry registration. Mirror recipe-blog as the reference.
allowed-tools: Read, Grep, Glob, Write, Edit
---

# Author a drupal-quick recipe

A recipe is a self-contained feature: a Drupal [recipe](https://www.drupal.org/project/distributions_recipes)
plus any config and theme assets it needs. Keep it **self-contained** — it ships
everything it touches and leaves no trace in unrelated files. Use `recipes/blog/`
as the canonical example.

## Layout

```
recipes/<name>/
├── recipe.yml                         # the Drupal recipe (install: lists the submodule)
├── config/                            # config this recipe creates
│   ├── field.storage.node.field_x.yml
│   ├── field.field.node.<bundle>.field_x.yml
│   └── taxonomy.vocabulary.x.yml
├── theme-assets/                      # optional: templates merged into the theme
│   └── templates/node--<bundle>.html.twig
└── module/                            # optional: behaviour (preprocess + JSON-LD)
    ├── dq_<name>.info.yml
    └── src/Hook/<Name>Hooks.php
```

## recipe.yml

```yaml
name: 'Blog'
description: 'Adds a Keywords vocabulary + field to Article.'
type: Content

install:
  - taxonomy            # modules this recipe needs enabled
  - dq_blog             # this recipe's own behaviour submodule (from module/)

config:
  # Config the recipe CREATES lives in config/ and is applied automatically.
  # config.actions modify config that already exists (from core/another recipe).
  actions:
    core.entity_view_display.node.article.default:
      setComponents:                  # pluralized action — each item is one setComponent()
        - name: field_keywords
          options: { type: entity_reference_label, label: above, weight: 15, region: content, settings: { link: true } }
```

Rules:
- Put config the recipe **owns** in `config/` (applied on import). Use
  `config.actions` only to tweak config that already exists.
- Depend on the content type being present (apply a core recipe like
  `core/recipes/standard` first) rather than recreating it.

## module/  (behaviour)

Preprocess and JSON-LD ship as a **submodule**. `dq:scaffold` assembles it under
`modules/custom/dq_hooks/modules/<machine>/` (machine name = the `*.info.yml`
basename) and the recipe's `install:` enables it. Behaviour is native OOP — a
class in `src/Hook/` with `#[Hook]` methods. Register under the **base hook** and
narrow by bundle/view id (the base hook fires for every bundle):

```php
// recipes/<name>/module/src/Hook/<Name>Hooks.php
namespace Drupal\dq_blog\Hook;
use Drupal\Core\Hook\Attribute\Hook;

final class BlogHooks {
  #[Hook('preprocess_node')]
  public function preprocessNode(array &$variables): void {
    if ($variables['node']->bundle() !== 'article') {
      return;
    }
    $variables['keywords'] = /* ... */;
  }
}
```

- The module needs a `dq_<name>.info.yml` (`type: module`,
  `core_version_requirement: ^11.3`, `dependencies:` for node/views/etc.).
- The namespace is the **module** machine name (`Drupal\dq_blog\Hook`) — no
  `STARTERKIT` token; module namespaces are independent of the theme.
- Multiple recipes may implement the same hook — each submodule is a separate
  extension, so they stack (see `dq-conventions` for why this is required).

## theme-assets/  (templates)

Plain Twig, copied into the generated theme by `dq:scaffold`. Reference the
variables your submodule sets. `dq:scaffold` replaces the literal token
`STARTERKIT` with the theme machine name in contents/filenames — only needed if a
template must name the theme (rare).

## Catalog metadata (self-describing)

The recipe declares its own catalog entry in **`composer.json`** — the package is
the single source of truth for its key and label:

```json
{
  "name": "drupal-quick/recipe-blog",
  "type": "drupal-recipe",
  "extra": { "dq": { "recipe": { "key": "blog", "label": "Blog — …" } } }
}
```

Do **not** hand-edit `templates/recipe-registry.json` — it is a generated cache.
Run `php bin/dq-registry-build` (or let drupal-quick CI run it) to enumerate the
recipe packages, read each `extra.dq.recipe`, and regenerate the registry
(`{ key: { label, package, url } }`; package + url are derived). See
`docs/recipe-registry.md`. A one-off recipe can still skip the catalog entirely
by referencing it inline in `config.dq.yml` (`{ package, url }`).

## Checklist

- [ ] `recipe.yml` declares `install:` modules and only owns config it ships.
- [ ] `config/` holds storage + instance + any vocab/display config.
- [ ] `module/` ships a `dq_<name>.info.yml` + `src/Hook/<Name>Hooks.php`; its
      `#[Hook]` methods register under the base hook with a bundle/view-id guard.
- [ ] `recipe.yml` `install:` lists the submodule so it gets enabled.
- [ ] Templates are in `theme-assets/`; any theme-name token uses `STARTERKIT`.
- [ ] `composer.json` declares `extra.dq.recipe` (`key` + `label`); regenerate
      the cache with `php bin/dq-registry-build` (never hand-edit it).
- [ ] Applying it on a fresh scaffold creates the fields and renders correctly.
