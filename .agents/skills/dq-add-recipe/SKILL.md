---
name: dq-add-recipe
description: Use when authoring a new drupal-quick recipe — a self-contained bundle of a Drupal recipe.yml, its config, and optional theme assets. Covers the directory layout, config actions, the STARTERKIT theme-asset pattern, and registry registration. Mirror recipes/blog/ as the reference.
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
├── recipe.yml                         # the Drupal recipe
├── config/                            # config this recipe creates
│   ├── field.storage.node.field_x.yml
│   ├── field.field.node.<bundle>.field_x.yml
│   └── taxonomy.vocabulary.x.yml
└── theme-assets/                      # optional: merged into the generated theme
    ├── templates/node--<bundle>.html.twig
    └── includes/<name>.theme.inc
```

## recipe.yml

```yaml
name: 'Blog'
description: 'Adds a Keywords vocabulary + field to Article.'
type: Content

install:
  - taxonomy            # modules this recipe needs enabled

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

## theme-assets/

Files here are copied into the generated theme by `dq:scaffold`, which replaces
the literal token `STARTERKIT` with the theme machine name in contents and
filenames. So:

- **Includes** register preprocess callbacks via the starterkit helper. Register
  under the **base hook** and narrow by bundle — a suggestion key like
  `node__article` never fires (see `dq-conventions`):

```php
// recipes/<name>/theme-assets/includes/<name>.theme.inc
STARTERKIT_add_preprocessor('node', function (array &$variables): void {
  if ($variables['node']->bundle() !== 'article') {
    return;
  }
  $variables['keywords'] = /* ... */;
});
```

- Helper functions you define must also carry the token so they get renamed:
  `function _STARTERKIT_thing(...)`.
- **Templates** are plain Twig; reference variables your include sets.

## Register in the recipe registry

Add the recipe to `templates/recipe-registry.json` so `dq-install` can resolve it:

```json
{ "blog": { "bundled": true, "path": "recipes/blog" } }
```

`bundled: true` ships inside this package. External recipes drop `bundled` and
add a GitHub URL; `dq-install` registers the VCS repo and `composer require`s it.
(External path resolution in `resolvePath()` is still a known TODO — see README.)

## Checklist

- [ ] `recipe.yml` declares `install:` modules and only owns config it ships.
- [ ] `config/` holds storage + instance + any vocab/display config.
- [ ] Theme-asset includes register under the base hook with a bundle guard.
- [ ] All theme-asset tokens use `STARTERKIT`, not a hardcoded theme name.
- [ ] Registered in `templates/recipe-registry.json`.
- [ ] Applying it on a fresh scaffold creates the fields and renders correctly.
