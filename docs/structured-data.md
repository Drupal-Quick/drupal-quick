# Structured data: Schema.org JSON-LD without a module

Quick emits [Schema.org](https://schema.org) structured data as JSON-LD
on its content pages **by hand, from each node's own fields** — no Metatag or
SEO module is installed. This keeps the footprint light and the output fully
static-export friendly: the markup is plain HTML that [Tome](https://tome.fyi)
captures as-is during `drush dq:static`.

The goal is two complementary layers on every content page:

1. **Semantic HTML5** — `<article>`, `<header>`, `<time datetime>`,
   `<address rel="author">`, a keyword `<nav>` — readable by humans, search
   crawlers and AI agents alike.
2. **A single JSON-LD block** describing the page as a Schema.org type.

---

## What gets emitted

| Content type | Schema.org type | Where it lives | Source |
| --- | --- | --- | --- |
| Article (blog recipe) | `BlogPosting` | inline in the article `<body>` via `{{ structured_data }}` | `recipe-blog` (`module/` builds it, `theme-assets/` template prints it) |
| Basic Page | `WebPage` | in the document `<head>` via an `html_head` attachment | starterkit `.theme` |

Both placements are valid — Google accepts JSON-LD in either the `<head>` or the
`<body>`. The article block is rendered from a template so the rewritten
`node--article.html.twig` doubles as a worked example of semantic markup; pages
have no custom node template, so their block is attached to the `<head>`
instead.

Each block is emitted **only on the full page view** (`view_mode == 'full'`), so
teasers and listing rows never produce duplicate structured data.

### Article — `BlogPosting`

Built in the blog recipe's submodule `recipe-blog/module/src/Hook/BlogHooks.php`
(`BlogHooks::articleJsonld()`) from:

- `headline` — node title
- `datePublished` / `dateModified` — node created/changed, ISO 8601, site timezone
- `mainEntityOfPage` — canonical absolute URL
- `author` — `Person` from the node owner
- `publisher` — `Organization` from the site name + theme logo
- `image` — absolute URL, from `field_media` (Media reference) then `field_image`
- `description` — body summary (or trimmed, tag-stripped body)
- `keywords` — array of Keyword term labels (`field_keywords`)

### Basic Page — `WebPage`

Built in the starterkit `.theme` (`dq_starterkit_page_jsonld()`) from the title,
canonical URL, dates, `isPartOf` (the site as a `WebSite`), `primaryImageOfPage`,
a body-summary `description`, and any `field_keywords`.

---

## How it's wired

The blog recipe ships a submodule (`dq_blog`) that `dq:scaffold` assembles under
the umbrella module and the recipe's `install:` enables. Its `BlogHooks` class
implements `#[Hook('preprocess_node')]` natively (module OOP hooks) and
narrows to Article with `$node->bundle() === 'article'`. Because the submodule is
its own extension, its preprocess stacks with the theme's and with other recipes
— no shared dispatcher is needed.

The page schema lives in the starterkit itself, which defines
`dq_starterkit_preprocess_node()` and scopes to the `page` bundle directly.

### Safety

The payload is encoded with
`json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)`.
`JSON_HEX_TAG` escapes `<` and `>` to `<` / `>`, so node content can
never break out of the `<script>` element; slashes stay literal so URLs read
cleanly. The result is wrapped in `Markup::create()` so the render system prints
it verbatim.

---

## Extending it

To cover another field or content type, edit the relevant builder:

- **Article** → `recipe-blog/module/src/Hook/BlogHooks.php`
  (`BlogHooks::articleJsonld()`).
- **Page / other bundles** → the starterkit `.theme`
  (`dq_starterkit_page_jsonld()`, or a new builder called from
  `dq_starterkit_preprocess_node()`).

Validate output with Google's
[Rich Results Test](https://search.google.com/test/rich-results) or the
[Schema.org validator](https://validator.schema.org/).

---

## When to reach for a module instead

The hand-built approach is ideal for a small, fixed set of content types. If a
project needs many Schema.org types, UI-managed field mappings, or per-page
overrides, a module is the better fit:

- **[Metatag](https://www.drupal.org/project/metatag) +
  [Schema.org Metatag](https://www.drupal.org/project/schema_metatag)** — the
  mainstream Drupal choice. Adds JSON-LD for Article, Person, Organization,
  BreadcrumbList and more, mapped through the UI. Medium footprint; the
  recommended upgrade path from the built-in markup.
- **[Schema.org Blueprints](https://www.drupal.org/project/schemadotorg)** —
  builds entity types directly from Schema.org definitions. Powerful, but a
  heavy install — the opposite of Quick's light footprint. Reach for it
  only when modelling complex, schema-first content.

If you adopt Metatag + Schema.org Metatag, drop the `{{ structured_data }}`
print from `node--article.html.twig` and the `html_head` attachment from
`dq_starterkit_preprocess_node()` to avoid emitting two competing blocks.
