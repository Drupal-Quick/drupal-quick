# Quick: zero to deployed

Full workflow from a blank DDEV project to a deployed static site.

---

## Prerequisites (one-time)

- DDEV installed
- Netlify CLI authenticated on the host (`netlify login`)
- GitHub access for the recipe/theme packages (SSH key or `ddev auth ssh`)

---

## 1 — Create a DDEV site

```bash
mkdir mysite && cd mysite
ddev config --project-type=drupal --php-version=8.3 --docroot=web
ddev start
```

---

## 2 — Require Quick

```bash
ddev composer require drupal-quick/drupal-quick
```

Pulls in `dq_starterkit` (the theme) and registers the `dq-init` and `dq-install` binaries.

---

## 3 — Initialise config

```bash
# Non-interactive — copies the template; edit it yourself
ddev composer exec dq-init

# Or interactive — walks you through site/theme/recipe choices
ddev composer exec -- dq-init --interactive

# Add --ddev to either form to also copy the DDEV local config template
ddev composer exec -- dq-init --ddev
```

Edit `config.dq.yml` to set your site name, theme machine name, preset, and recipes.

A recipe entry can be a registry key, a core/contrib path, or an inline package spec for a one-off recipe with no registry edit:

```yaml
recipes:
  - "core/recipes/standard"
  - "blog"
  - { package: "you/recipe-x", url: "https://github.com/you/recipe-x" }
```

Available registry keys out of the box: `blog`, `project`. See `templates/recipe-registry.json` for the full catalog and [docs/recipe-registry.md](recipe-registry.md) for how the registry works.

---

## 4 — Install recipe packages

```bash
ddev composer exec dq-install
```

Reads `config.dq.yml`, registers any recipe VCS repos in `composer.json`, and `composer require`s each recipe package. `core-recipe-unpack` unpacks them into `recipes/`.

> **Local dev note:** if recipe/theme packages are on your local machine rather than published to a Git remote, add them as path repos in `composer.json` before this step. VCS repos (GitHub) require DDEV to have GitHub auth — run `ddev auth ssh` first.

---

## 5 — Scaffold the site

```bash
ddev drush dq:scaffold
```

This single command:

1. Installs Drupal (`drush site:install`)
2. Generates a real theme from the starterkit (`drupal generate-theme`)
3. Writes any `theme_design` overrides from `config.dq.yml` to `presets/overrides.css`
4. Applies each recipe in order (`drush recipe …`), injecting recipe `theme-assets/` into the theme
5. Installs deps and applies the chosen design preset — `npm install && npm run preset` — which fetches any preset fonts and builds the theme (unless `build: false`)
6. Applies any `recipe_config` overrides
7. Rebuilds caches

The site is live at `https://mysite.ddev.site`.

---

## 6 — Add content

Log in at `/user/login` with the credentials shown at the end of scaffold (or the `admin_pass` you set in `config.dq.yml`). Add articles, projects, or whatever the applied recipes installed.

---

## 7 — Export to static HTML

Add a `static:` block to `config.dq.yml` if it isn't there already:

```yaml
static:
  target: "netlify"            # netlify | github | none
  uri: "https://your-site.netlify.app"
```

Build the theme, then export:

```bash
# Vite dev mode must be off — build first
cd web/themes/custom/my_custom_theme && npm run build && cd -

ddev drush dq:static
```

Installs Tome, exports all routes to `html/`, and writes `netlify.toml`. Settings are persisted to Drupal config (`drupalquick.static`) so they survive `dq:cleanup`.

> **Caveats:** forms, search, and authenticated content are not in the static export. Dynamic features need a third-party substitute (Netlify Forms, Pagefind, etc.). See [docs/static-deploy.md](static-deploy.md) for full caveats and design notes.

---

## 8 — Deploy

**Option A — from the host** (simplest; uses your `netlify login` session):

```bash
netlify deploy --prod --dir=html
```

**Option B — from inside DDEV** (credentials stay in the container):

```bash
cp .ddev/.env.web.example .ddev/.env.web
# edit .ddev/.env.web: set NETLIFY_AUTH_TOKEN (and optionally NETLIFY_SITE_ID)
ddev restart
ddev drush dq:static            # generate the static export → html/
ddev drush dq:deploy            # publish it to the configured target
```

Never put the token in `.ddev/config.local.yaml` — its `web_environment` is committed to git.

---

## Optional — clean up

Once the site is deployed and you no longer need the scaffolding tools locally:

```bash
ddev drush dq:cleanup
```

Removes the Quick package, redacts `admin_pass` from `config.dq.yml`, and archives it. The deployed static site is unaffected.
