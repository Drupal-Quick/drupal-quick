# Dogfooding: quickthe.me

The project site (quickthe.me) is built **with** Quick: scaffolded by the real
`dq-init → dq-install → dq:scaffold` flow, then hand-edited — exactly the
workflow we tell users to follow. The site lives in its own repo
(`quickthe-me`, `~/dev/quickthe-me`): a `drupal-quick/site`-shaped project
whose first commit is the untouched scaffold output, so every hand edit after
it is a reviewable diff. Sections: marketing front page, `/docs/*` (Basic
pages + a docs menu block in the sidebar region), `/generator` (the
tools/generator app inside the site shell), `/presets` (a `showcase` content
type + view; each card carries a complete config.dq.yml and real screenshots
of a site scaffolded from it).

This file records what the dogfooding surfaced and where it should flow back
into the packages.

## Fixed during the exercise

- **`hidden md:block` never becomes visible** (dq-starterkit): Drupal core's
  `system.module.css` also defines `.hidden` and loads *after* the inlined
  theme CSS, so the sidebar shell's site title was `display:none` at every
  width. Fixed with `max-md:hidden`. Rule of thumb for starterkit and recipe
  templates: never use Tailwind's bare `hidden` on elements a breakpoint
  variant is meant to reveal.
- **recipe-project was not self-contained on Drupal 11.4**: core's Standard
  recipe stopped shipping content types there, so nothing provided the node
  `body` field storage and applying `project` without `blog` failed. The
  recipe now ships core's `field.storage.node.body.yml` verbatim (a validated
  no-op where it already exists) — the same class of fix recipe-blog got with
  its `article_content_type` dependency.

## Should flow into the scaffold

- **Theme-enable default blocks** (the already-deferred cleanup, now clearly
  needed): when `dq:scaffold` enables the generated theme, Drupal copies the
  previous default theme's block placements into it — a broken-logo branding
  block plus admin/tools menu blocks land in the header on every scaffolded
  site. The scaffold should delete those three placements and put the main
  menu block into the shell's `primary_menu` region (label hidden). Both
  quickthe.me's `scripts/site-setup.php` and the screenshot factory's seeder
  had to do this by hand.
- **Document the 11.4 standard-recipe change**: configs that need Basic page
  must list `core/recipes/page_content_type` explicitly. Worth a comment in
  `templates/config.dq.yml` and possibly a registry-level hint.

## Recipe opportunities (not built, recommended)

quickthe.me was hand-composed; each of these is a candidate to become a Quick
recipe so a future config could reach the same starting point:

- **docs**: a documentation section — dedicated `docs` menu, menu block in the
  sidebar region with `/docs*` visibility, a docs page-shell variant (sticky
  left nav + reading column), rich-text typography for code-heavy bodies, and
  the code-block copy behavior.
- **showcase**: the gallery — content type (config text + multi-image), teaser
  grid view (page + embed block), carousel/copy teaser templates. Generalizes
  to any "cards with images and a copyable payload" gallery.
- **marketing-front**: hero (heading, tagline, copyable command, CTA pair),
  alternating feature bands, and a teaser band — as advertised homepage blocks.

Before building a docs recipe, evaluate contrib: `search_api` (+ database
backend) for docs search, `toc_api`/`toc_js` for per-page tables of contents,
`linkchecker` for rot. Navigation needs no contrib — a core menu block with
depth covers it.

## Other observations

- **Sample content should ship with recipes** (existing catalog.js @todo,
  reinforced): gallery screenshots required hand-seeding articles/projects
  (`dq-shot`'s seed script mirrors the generator's dummy data). If recipes
  carried a sample-content manifest, both the generator preview and a
  `--sample-content` scaffold flag could draw from it, and screenshot
  pipelines would be trivial.
- **Alpine.js integration**: the site vendors Alpine through the theme's Vite
  bundle (npm dep, registered components, `Alpine.start()` before Drupal
  behaviors decorate content). Worked cleanly; if more sites want
  interactivity, an optional starterkit integration (or a `dq-alpine`
  submodule pattern) could package this.
- **`Node::create(['path' => …])` does not reliably persist aliases** in
  site scripts — create `PathAlias` entities explicitly.
- **Twig sandbox**: `FieldItemList::count()` is not an allowed method in
  templates; use `|length`.

## Sync contract reminder

The generator gained the `quick` preset in its static catalog — mirrored in
`tools/generator/catalog.js` (source of record) and the site's bundled copy
(`quickthe-me` theme `src/generator/catalog.js`). The real fix remains the
registry emitting a shared JSON contract both consume (see
tools/generator/README.md).
