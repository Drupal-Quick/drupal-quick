# Static site export and deploy (Tome)

Quick's goal is *quick* in two senses: fast provisioning **and** a fast
end result. Part of that is the ability to ship the finished site as a static,
HTML-only build — provision Drupal locally, scaffold a theme and content, remove
every trace of Quick, then deploy a performant static site.

This is done with [Tome](https://tome.fyi) (the `tome_static` submodule) driven
by the `drush dq:static` command. No front-end framework is involved — the
output is plain HTML/CSS/JS rendered by Drupal itself.

---

## Why Tome

- **Drush-driven** (`drush tome:static`) — the same execution model the rest of
  Quick uses.
- **Plain HTML output.** Tome renders every anonymous-accessible route and
  entity canonical path through Drupal's own HTTP kernel, then collects the
  referenced stylesheets, scripts, images (incl. `srcset`), favicons, and pager
  links. The theme's built `dist/main.css` / `dist/main.js` are captured as-is.
- **Reinforces "no footprint of Quick."** The deployed artifact is just static
  files — no Drupal, no PHP, no Quick. Drupal + Tome remain locally as the
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
# then publish the export to the configured target:
ddev drush dq:deploy
```

`dq:deploy` is a separate command (so generation and publishing each carry their
own flags). It reads the `target` from the persisted `drupalquick.static` config
(seeded by `dq:static`), writes the target's deploy config, and pushes. Only
Netlify is automated for now: `netlify deploy --prod --dir=html`, using a
globally installed `netlify` CLI if present, otherwise `npx netlify-cli`. GitHub
Pages deploys via its own workflow (git push), so for that target `dq:deploy`
just writes `.github/workflows/deploy-pages.yml`. It is loosely coupled to the
build: if `html/` is missing it tells you to run `dq:static` first rather than
regenerating implicitly.

### Previewing the export under DDEV

```bash
ddev drush dq:static   # inside DDEV the preview vhost is provisioned automatically
ddev restart           # once, to activate the new hostname
# then browse https://static.<project>.ddev.site
```

When `dq:static` runs inside a DDEV web container (detected via DDEV's
`IS_DDEV_PROJECT` environment variable) the preview vhost is provisioned
automatically; pass `--no-ddev-preview` to opt out, or `--ddev-preview` to
require it (outside DDEV, or to make a missing `.ddev/` an error instead of
a note). The provisioning creates a second vhost in the same DDEV project that serves the
export directory as plain files (no PHP handler), beside the live site — so
the export can be checked exactly as a host would serve it, and re-exports
are just a refresh away. It writes two files:

- `.ddev/nginx_full/static.conf` — a static-only nginx server block rooted
  at the export directory.
- `.ddev/config.static.yaml` — a DDEV config override registering
  `static.<project>` in `additional_hostnames`, so the user's own
  `config.yaml` is never edited.

Both carry a `#dq-generated` marker: they are rewritten on every
provisioning run until the marker line is removed, at which point the
tool leaves them alone (the same ownership convention DDEV uses for its
generated files). The command runs inside the web container, so it cannot
restart DDEV itself — hence the one-time `ddev restart`.

Caveat: DDEV config overrides *replace* list values. If the project already
declares `additional_hostnames` in `config.yaml`, fold the static hostname
into that list and delete `config.static.yaml`.

### Deploy credentials

`dq:deploy` runs inside the DDEV web container, so the credentials must be present
there — and they are secrets, so they must stay out of version control. The
mechanism:

- `dq-init --ddev` delivers `.ddev/.env.web.example` (keys, no values) and adds
  `.ddev/.env.web` to `.gitignore`.
- Copy it and fill in your token:

  ```bash
  cp .ddev/.env.web.example .ddev/.env.web
  # edit .ddev/.env.web → NETLIFY_AUTH_TOKEN=...  (NETLIFY_SITE_ID optional)
  ddev restart
  ```

DDEV loads `.ddev/.env.web` into the web container, where `netlify deploy` picks
the token up. **Never put the token in `config.local.yaml`** (its
`web_environment` is committed) or anywhere tracked.

Don't want secrets in the container at all? Skip `dq:deploy` and deploy the export
from the **host**, where `netlify login` already stored credentials:

```bash
netlify deploy --prod --dir=html
```

`dq:static` will:

1. Resolve settings (persisted config, else `config.dq.yml`).
2. `composer require drupal/tome` and enable `tome_static` if not already present.
3. Persist `target`/`uri` to `drupalquick.static` (so it survives `dq:cleanup`).
4. Preflight the active theme — abort if the Vite dev marker is present, warn if
   `dist/main.css` is missing.
5. Run `drush tome:static` (with `--uri` if configured).

`dq:deploy` then:

6. Confirms `html/` exists (else tells you to run `dq:static`).
7. Writes the deploy config for the target (`netlify.toml` or
   `.github/workflows/deploy-pages.yml`).
8. Pushes the export to the target (Netlify automated; GitHub via its workflow).

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

## Deploy target design (when target #2 arrives)

Today `deployStatic()` handles one automated target (Netlify). Don't
pre-abstract for one target — but it would balloon if more were added as
inline branches, so here is the intended progression:

1. **At target #2:** turn `deployStatic()` into a thin dispatcher and give each
   target its own small method, so each one's quirks (auth checks, flags) stay
   isolated:

   ```php
   private function deployStatic(string $target): int {
     return match ($target) {
       'netlify' => $this->deployNetlify(),
       'vercel'  => $this->deployVercel(),
       'github'  => 0, // deploys via git push; no-op here
       default   => $this->warnUnsupported($target),
     };
   }
   ```

   `deployStatic()` stays ~6 lines regardless of target count.

2. **If targets trend homogeneous** (most static hosts are "run a CLI against the
   output dir": `netlify`, `vercel`, `surge`, `wrangler pages`, `aws s3 sync`,
   `firebase`), promote to a data-driven `deploy-targets` map — the same pattern
   as `recipe-registry.json` — so a new target is data, not code. Keep a method
   escape hatch for non-CLI targets: GitHub Pages deploys via git push, not a
   one-line command.

3. **Only for external contributions:** a `StaticDeployerInterface` with one
   class per target and discovery. This is the textbook answer but overkill now,
   and it fights the runtime context — `dq:static` runs in a Drush command with
   an unreliable container, so heavy DI/plugin discovery is awkward. Reserve it
   for when packages must contribute their own deployers (see
   [extensibility.md](extensibility.md)).

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
