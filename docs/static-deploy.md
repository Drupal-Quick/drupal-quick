# Static site export and deploy (Tome)

drupalquick's goal is *quick* in two senses: fast provisioning **and** a fast
end result. Part of that is the ability to ship the finished site as a static,
HTML-only build — provision Drupal locally, scaffold a theme and content, remove
every trace of Quick, then deploy a performant static site.

This is done with [Tome](https://tome.fyi) (the `tome_static` submodule) driven
by the `drush dq:static` command. No front-end framework is involved — the
output is plain HTML/CSS/JS rendered by Drupal itself.

---

## Why Tome

- **Drush-driven** (`drush tome:static`) — the same execution model the rest of
  drupalquick uses.
- **Plain HTML output.** Tome renders every anonymous-accessible route and
  entity canonical path through Drupal's own HTTP kernel, then collects the
  referenced stylesheets, scripts, images (incl. `srcset`), favicons, and pager
  links. The theme's built `dist/main.css` / `dist/main.js` are captured as-is.
- **Reinforces "no footprint of Quick."** The deployed artifact is just static
  files — no Drupal, no PHP, no drupalquick. Drupal + Tome remain locally as the
  authoring/build environment; `dq:cleanup` still removes Quick itself.
- **Deploy targets built in** — Tome documents GitHub Pages, Netlify, Render,
  and more.
- **Drupal 11 support** — Tome `8.x-1.14` (Feb 2026) requires `^10 || ^11` and is
  covered by Drupal's security advisory policy. Note it is *minimally
  maintained / feature-complete*, so treat it as stable-but-static, not actively
  evolving.

---

## Architecture: why config + command (and recipe later)

Static export spans three concerns, each with a natural home:

| Concern | Home | Why |
| --- | --- | --- |
| Project settings (deploy target, base URL) | `config.dq.yml` `static:` block | The single file the user already edits |
| The operation (install Tome, build check, export, emit deploy config) | `drush dq:static` command | Recurring/imperative; **recipes cannot run an export** |
| Capability install (Tome module + its config) | a recipe *(future)* | Declarative/composable — but premature for one contrib module |

So the current design is **config + command**, not a recipe. A recipe can't
perform the export, so the command is required regardless, and a recipe that only
does `install: [tome_static]` is too thin to justify today. Once the recipe
ecosystem is externalized and self-describing (see
[extensibility.md](extensibility.md)), "static export capability" becomes a
natural candidate to extract into a `recipe-static`.

### Settings persistence (important)

`dq:cleanup` deletes `config.dq.yml`, but static export is **recurring** — you
re-export whenever content changes, often long after Quick is gone. So settings
cannot live only in `config.dq.yml`. `dq:static` therefore **persists** the
resolved settings into Drupal config (`drupalquick.static`) on first run.
Resolution order:

1. `drupalquick.static` config (survives cleanup) — wins if present.
2. Otherwise the `static:` block in `config.dq.yml` — seeds the first run.

This is also why a recipe is the eventual right home: it would write that
persisted config declaratively.

---

## Usage

```yaml
# config.dq.yml
static:
  target: "netlify"            # netlify | github | none
  uri: "https://example.com"   # base URL for absolute links (optional)
```

```bash
# after the site is scaffolded and the theme is built
ddev drush dq:static
# or override the base URL ad hoc:
ddev drush dq:static --base-url=https://example.com
# export AND push to the configured target in one step:
ddev drush dq:static --deploy
```

`--deploy` runs after the export and pushes to the configured `target`. Only
Netlify is automated for now: it runs `netlify deploy --prod --dir=html`, using a
globally installed `netlify` CLI if present, otherwise `npx netlify-cli`. The CLI
must be authenticated (`netlify login`, or a `NETLIFY_AUTH_TOKEN` env var) and the
site linked (`netlify.toml` / `netlify link` / `NETLIFY_SITE_ID`). Because the
command runs inside the DDEV web container, the CLI and credentials must be
available there. GitHub Pages deploys via its own workflow (git push), so
`--deploy` is a no-op for that target.

`dq:static` will:

1. Resolve settings (persisted config, else `config.dq.yml`).
2. `composer require drupal/tome` and enable `tome_static` if not already present.
3. Persist `target`/`uri` to `drupalquick.static`.
4. Preflight the active theme — abort if the Vite dev marker is present, warn if
   `dist/main.css` is missing.
5. Run `drush tome:static` (with `--uri` if configured).
6. Write the deploy config for the target (`netlify.toml` or
   `.github/workflows/deploy-pages.yml`).
7. With `--deploy`, push the export to the target (Netlify only for now).

Output lands in `html/` (Tome's default; override with
`$settings['tome_static_directory']` in `settings.php`).

---

## Caveats

- **Dynamic features don't survive static.** Forms, search, comments, and
  anything authenticated are gone. The blog/content-display starter is fine;
  search would need a static approach (e.g. Pagefind/Lunr) and forms a
  third-party (Netlify Forms / a function).
- **Vite dev mode must be off.** If the `.vite-dev` marker exists, the theme
  injects `localhost:5173` dev-server tags, which the export would capture.
  `dq:static` aborts if it detects the marker. Run `npm run build` first.
- **Build the theme first.** Without `dist/main.*`, the export is unstyled.
- **Base path / rewrites vary by host.** Subdir vs root and clean-URL rewrites
  differ per platform; the emitted deploy config is a starting point.
- **Tome maintenance.** Stable on D11 but feature-complete/fixes-only.

---

## Alternatives considered

- **Static Suite** — actively maintained but built around exporting JSON for an
  *external* SSG (Astro/Gatsby/Eleventy), which reintroduces a JS build chain —
  contrary to the "no framework / keep it light" goal.
- **Static Generator / Static / Static Node Generator** — partial or on-demand
  exporters with smaller communities and weaker D11 maintenance.
- **Crawl/mirror tools (wget/httrack)** — crude and fragile with Drupal asset
  URLs.

Tome remains the best fit for full-site, framework-free HTML output.

---

## Suggested phasing

1. **Now:** `config.dq.yml` `static:` block + `dq:static` command (this
   prototype) — installs Tome idempotently, persists settings, exports, emits a
   deploy template.
2. **Next:** richer deploy targets and a `--build` flag that runs the theme build
   before exporting.
3. **Later:** extract Tome install + config into a `recipe-static` once recipes
   are externalized, and consider a static search integration (Pagefind) for the
   lost dynamic search.
