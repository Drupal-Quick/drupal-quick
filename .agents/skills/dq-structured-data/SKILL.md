---
name: dq-structured-data
description: Use when adding or changing Schema.org JSON-LD / structured data on a drupal-quick content type. Enforces the module-free, hand-built approach (no Metatag/SEO module) with correct safety, absolute URLs, and view-mode scoping. See docs/structured-data.md.
allowed-tools: Read, Grep, Glob, Write, Edit
---

# Structured data the drupal-quick way

Emit Schema.org JSON-LD **by hand from each node's own fields** — no Metatag or
SEO module. Keeps the footprint light and survives the Tome static export
unchanged. Reference implementation: `recipes/blog/theme-assets/includes/blog.theme.inc`
(article `BlogPosting`) and the starterkit `.theme` (`WebPage` for pages).
Full rationale in `docs/structured-data.md`.

## Rules

1. **Build in PHP, not Twig.** Assemble a render array in a preprocess callback
   and return an `html_tag` script element. Never hand-build JSON strings in a
   template.
2. **Full view only.** Guard on `($variables['view_mode'] ?? '') === 'full'` so
   teasers/listings never emit duplicate blocks.
3. **One block per page.** Article = `BlogPosting`; basic page = `WebPage`. Scope
   each by bundle so they never both fire on one node.
4. **Absolute URLs.** Images via `file_url_generator->generateAbsoluteString()`;
   canonical via `$node->toUrl('canonical', ['absolute' => TRUE])`.
5. **ISO 8601 dates** in site timezone: `date.formatter->format($ts, 'custom', 'c')`.
6. **Drop `<pre>` before description.** `strip_tags()` flattens code samples into
   the summary — remove `<pre>…</pre>` first.

## The render element

```php
$json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
return [
  '#type' => 'html_tag',
  '#tag' => 'script',
  '#attributes' => ['type' => 'application/ld+json'],
  '#value' => \Drupal\Core\Render\Markup::create($json),
];
```

`JSON_HEX_TAG` escapes `<`/`>` so content can never break out of the `<script>`;
`Markup::create()` stops the render system re-escaping it.

## Placement

- **Has a custom node template** (e.g. article via the blog recipe): set
  `$variables['structured_data']` and print `{{ structured_data }}` in the
  template. Visible and co-located.
- **No custom template** (e.g. basic page): attach to the `<head>`:

```php
$variables['#attached']['html_head'][] = [$element, 'schema_webpage'];
```

Both are valid — Google accepts JSON-LD in `<head>` or `<body>`.

## Field resolution (this content model)

Lead image: prefer the Media reference (`field_media` → `field_media_image` →
file), fall back to a plain `field_image`. Keywords: flat term labels from
`field_keywords`. Guard every field with `hasField()` + `!isEmpty()`.

## Validate

After changes, confirm one valid block renders and parses:

```bash
ddev drush cr
curl -sk https://<site>/node/<id> | grep -o 'application/ld+json'
```

Then run the output through Google's
[Rich Results Test](https://search.google.com/test/rich-results) or the
[Schema.org validator](https://validator.schema.org/). Required for an `Article`:
`headline`, `image`, `datePublished`.

## If a project outgrows hand-built JSON-LD

Switch to **Metatag + Schema.org Metatag** (UI-mapped). If you do, remove the
`{{ structured_data }}` print and the `html_head` attachment so you don't emit
two competing blocks.
