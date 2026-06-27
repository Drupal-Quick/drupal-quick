# drupal-quick agent skills

Portable, tool-agnostic [Agent Skills](https://skills.sh) for working on
drupal-quick. Skills live here (`.agents/skills/`) as the canonical source so
they travel with the repo and work across agents; Claude Code discovers them
through a symlink (see **Wiring** below).

Each skill is a folder with a `SKILL.md` (required) plus optional
`scripts/`, `references/`, `assets/`:

```
.agents/skills/
  dq-conventions/        # Knowledge — architecture + the project's gotchas
  dq-scaffold-site/      # Process   — provision a site end to end
  <your-skill>/
    SKILL.md
```

## SKILL.md frontmatter

```yaml
---
name: skill-name              # invoke manually as /skill-name
description: When to use this  # the trigger — be specific and slightly "pushy"
allowed-tools: Read, Grep      # optional: grant tools without per-use approval
disable-model-invocation: true # optional: manual-only (no auto-trigger)
---
```

Only `description` is required. Keep bodies tight (≤ ~5k tokens), imperative
("Always run X", not "consider X"), and start with explicit trigger conditions.

## Three kinds of skill

- **Knowledge** — domain expertise and conventions (e.g. `dq-conventions`).
- **Process** — multi-step workflows with checkpoints (e.g. `dq-scaffold-site`).
- **Intent** — quality gates that apply continuously (security, light-footprint,
  accessibility).

## Skill catalog (recommended for this repo)

| Skill | Kind | Status | Purpose |
| --- | --- | --- | --- |
| `dq-conventions` | Knowledge | ✅ built | The scaffold flow, STARTERKIT token, the preprocess-dispatch gotcha, Vite/Tailwind wiring, light-footprint rules |
| `dq-scaffold-site` | Process | ✅ built | Run `dq-init → dq-install → drush dq:scaffold` (host or DDEV) to provision a site |
| `dq-add-recipe` | Process | ✅ built | Author a new recipe: `recipe.yml` + config + `theme-assets/` following conventions |
| `dq-structured-data` | Intent | ✅ built | Add/validate module-free Schema.org JSON-LD the dq way (see `docs/structured-data.md`) |
| `dq-theme-build` | Process | ⬜ proposed | Generate/iterate the theme; Vite + Tailwind v4 build; HMR dev loop |
| `dq-static-deploy` | Process | ⬜ proposed | `drush dq:static` (Tome export) → `drush dq:deploy` → Netlify/GitHub Pages |
| `dq-light-footprint` | Intent | ⬜ proposed | Quality gate: prefer Drupal-native, avoid needless modules, no generated-by markers, semantic markup |

## Registry skills (install via skills.sh, don't reinvent)

For *generic* Drupal knowledge, pull from the registry instead of authoring it
here — keep local skills dq-specific. Cherry-pick; don't bulk-install. Registry
skills install to `.claude/skills/`, which symlinks here, so they sit alongside
the authored ones.

**Backbone — install now:**

```bash
npx skills add grasmash/drupal-claude-skills --skill drupal-at-your-fingertips      # 50+ core topics (Selwyn Polit)
npx skills add grasmash/drupal-claude-skills --skill drupal-ddev                    # DDEV — matches the dq workflow
npx skills add grasmash/drupal-claude-skills --skill skill-developer               # meta: authoring skills well
npx skills add grasmash/drupal-claude-skills --skill ivangrynenko-cursorrules-drupal  # OWASP Top 10 security
```

**Add as the project grows:** `drupal-config-mgmt`, `drupal-testing`,
`drupal-contrib-mgmt` (same `--skill` form).

**Security — pick one, not both:** `ivangrynenko-cursorrules-drupal` (OWASP,
comprehensive) *or* the lighter
`npx skills add https://github.com/madsnorgaard/agent-resources --skill drupal-security`.

**Skip for now:** `drupal-simple-oauth`, `drupal-search-api`,
`drupal-config-reconcile` (feature-specific) and all Canvas/Acquia skills
(`drupal-canvas`, `drupal-canvas-sdc`, `canvas-contribution`, `acquia-source`) —
they target a different theming stack than the Vite + Tailwind classic theme.

## Wiring (so Claude Code sees these)

Claude Code discovers skills in `.claude/skills/`. Bridge it to this folder once:

```bash
ln -s ../.agents/skills .claude/skills
```

Now `/dq-conventions`, `/dq-scaffold-site`, etc. resolve here. Other agents
(Cursor, Codex, …) can point at `.agents/skills/` directly. `npx skills add`
installs into `.claude/skills/`; with the symlink in place, keep authored skills
here and let the registry ones land alongside.
