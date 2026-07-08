/**
 * Alpine component for the config.dq.yml generator POC.
 *
 * The YAML emitter reproduces the canonical file byte-for-byte where it can:
 * section comments come verbatim from templates/config.dq.yml, the commented
 * option blocks mirror RecipeOptions::activeLines(), and the block catalog
 * mirrors RecipeBlocks::commentedCatalog(). If those change, change this too
 * (until the registry emits a shared JSON contract both sides consume).
 */
function dqGenerator() {
  return {
    open: 'site',
    tab: 'preview',
    copied: false,

    site: { name: 'My Tailored Drupal Site', admin_user: 'admin', admin_pass: '' },
    theme: { machine_name: 'my_custom_theme', title: 'My Custom Theme', preset: 'minimal', layout: 'sidebar', build: true },
    design: { primary_color: '', font_family: '' },
    selected: Object.fromEntries(DQ_CATALOG.recipes.map(r => [r.key, false])),
    options: Object.fromEntries(DQ_CATALOG.recipes.map(r =>
      [r.key, Object.fromEntries(r.options.map(o => [o.name, String(o.default)]))]
    )),
    homepage: [],
    extras: DQ_CATALOG.extras.map(e => ({ ...e, enabled: false, value: e.sample })),

    // ---------------------------------------------------------------- UI

    toggleSection(s) { this.open = this.open === s ? '' : s; },

    get catalog() { return DQ_CATALOG; },

    recipe(key) { return DQ_CATALOG.recipes.find(r => r.key === key); },

    get selectedRecipes() {
      return DQ_CATALOG.recipes.filter(r => this.selected[r.key]);
    },

    onRecipeToggle(key) {
      if (!this.selected[key]) {
        this.homepage = this.homepage.filter(id => id.split('/')[0] !== key);
      }
    },

    // ------------------------------------------------------------ blocks

    get availableBlocks() {
      const out = [];
      for (const r of this.selectedRecipes) {
        for (const b of r.blocks) {
          out.push({ id: `${r.key}/${b.key}`, recipe: r.key, ...b });
        }
      }
      return out;
    },

    get unplacedBlocks() {
      return this.availableBlocks.filter(b => !this.homepage.includes(b.id));
    },

    blockMeta(id) {
      return this.availableBlocks.find(b => b.id === id);
    },

    addBlock(id) { this.homepage.push(id); },
    removeBlock(id) { this.homepage = this.homepage.filter(x => x !== id); },
    moveBlock(i, dir) {
      const j = i + dir;
      if (j < 0 || j >= this.homepage.length) return;
      const h = [...this.homepage];
      [h[i], h[j]] = [h[j], h[i]];
      this.homepage = h;
    },

    // ----------------------------------------------------------- preview

    get previewTokens() {
      const p = DQ_CATALOG.presets.find(p => p.key === this.theme.preset) || DQ_CATALOG.presets[0];
      const t = { ...p.tokens };
      if (this.design.primary_color) t.primary = this.design.primary_color;
      if (this.design.font_family) t.font = this.design.font_family;
      return t;
    },

    get previewStyle() {
      const t = this.previewTokens;
      return `--pv-primary:${t.primary};--pv-secondary:${t.secondary};--pv-accent:${t.accent};` +
        `--pv-paper:${t.paper};--pv-ink:${t.ink};--pv-muted:${t.muted};--pv-rule:${t.rule};` +
        `--pv-font:${t.font};--pv-title:${t.title};--pv-title-lh:${t.titleLh};` +
        `--pv-meta:${t.meta};--pv-meta-lh:${t.metaLh};--pv-flow:${t.flow};--pv-row:${t.row}`;
    },

    get previewNav() {
      const items = ['Home'];
      if (this.selected.blog) items.push('Writing');
      if (this.selected.project) items.push('Projects');
      items.push('About');
      return items;
    },

    // What the front page shows when homepage: is not composed.
    get fallbackFront() {
      if (this.selected.blog) return 'writing';
      return 'welcome';
    },

    articlesFor() {
      const all = this.recipe('blog').dummy.articles;
      const n = parseInt(this.options.blog.items_per_page, 10);
      return all.slice(0, Math.max(1, Math.min(isNaN(n) ? all.length : n, all.length)));
    },

    // -------------------------------------------------------------- YAML

    yaml() {
      const L = [];
      this.emitSite(L);
      L.push('');
      this.emitTheme(L);
      L.push('');
      this.emitRecipes(L);
      L.push('');
      this.emitBlockCatalog(L);
      this.emitHomepage(L);
      this.emitParameters(L);
      return L.join('\n') + '\n';
    },

    q(s) { return '"' + String(s).replace(/"/g, '\\"') + '"'; },

    emitSite(L) {
      L.push('site:');
      L.push(`  name: ${this.q(this.site.name)}`);
      L.push(`  admin_user: ${this.q(this.site.admin_user)}`);
      if (this.site.admin_pass) {
        L.push('  # Set explicitly — lives here in plaintext until `dq:cleanup` redacts it');
        L.push('  # on archive.');
        L.push(`  admin_pass: ${this.q(this.site.admin_pass)}`);
      } else {
        L.push('  # Leave admin_pass unset to have a strong password generated and shown once');
        L.push('  # during `drush dq:scaffold`. Set it explicitly only if you must — it then');
        L.push('  # lives here in plaintext until `dq:cleanup` redacts it on archive.');
        L.push('  # admin_pass: "change-me"');
      }
    },

    emitTheme(L) {
      L.push('theme:');
      L.push(`  machine_name: ${this.q(this.theme.machine_name)}`);
      L.push(`  title: ${this.q(this.theme.title)}`);
      L.push('  # A design preset shipped by the starterkit (see its package.json "dq.presets").');
      L.push('  # Available out of the box: minimal, corporate, geometric. Omit to use the');
      L.push('  # starterkit\'s default. Change it later with `npm run preset <name>` inside the theme.');
      L.push(`  preset: ${this.q(this.theme.preset)}`);
      L.push('  # Page-shell arrangement, baked into the theme at scaffold time: "sidebar"');
      L.push('  # (site title atop a vertical left menu — the default) or "single" (one');
      L.push('  # column, title + horizontal menu along the top). Afterwards the shell is an');
      L.push('  # ordinary template you own — edit templates/includes/page-shell.html.twig');
      L.push('  # in the generated theme to change or restyle the layout.');
      L.push(`  layout: ${this.q(this.theme.layout)}`);
      L.push('  # Set to false to skip `npm install && npm run build` after theme generation.');
      L.push('  # Useful when deferring the build to a CI step or building manually later.');
      L.push(`  build: ${this.theme.build}`);
    },

    emitRecipes(L) {
      L.push('# Each recipe entry can be:');
      L.push('#   - a short key from the registry (templates/recipe-registry.json), e.g. "blog"');
      L.push('#   - a core/contrib path, e.g. "core/recipes/standard"');
      L.push('#   - a key plus per-recipe options (the recipe\'s native inputs — all optional,');
      L.push('#     with sane defaults):');
      L.push('#       - name: "blog"');
      L.push('#         options:');
      L.push('#           items_per_page: 10');
      L.push('#   - an inline package spec for an ad-hoc recipe (no registry edit needed):');
      L.push('#       - { package: "you/recipe-x", url: "https://github.com/you/recipe-x" }');
      L.push('# Run `composer exec dq-install` after editing — it fetches any package recipes');
      L.push('# (registry keys or inline specs) before `drush dq:scaffold`, and writes each');
      L.push('# recipe\'s available options here as a commented block under its entry, ready to');
      L.push('# uncomment. Pass --exclude-options to just list them in the terminal instead.');
      L.push('recipes:');
      L.push('  - "core/recipes/standard"');
      for (const r of this.selectedRecipes) {
        if (!r.options.length) {
          L.push(`  - ${this.q(r.key)}`);
          continue;
        }
        const touched = r.options.some(o => String(this.options[r.key][o.name]) !== String(o.default));
        L.push(`  - name: ${this.q(r.key)}`);
        for (const line of this.optionLines(r, touched)) {
          L.push(touched ? line : '# ' + line);
        }
      }
    },

    // Mirrors RecipeOptions::activeLines(): options: at 4 spaces, keys at 6,
    // description as a trailing comment.
    optionLines(r, useCurrent) {
      const lines = ['    options:'];
      for (const o of r.options) {
        const raw = useCurrent ? this.options[r.key][o.name] : o.default;
        const value = o.type === 'integer' || o.type === 'float' ? String(raw) : this.q(raw);
        let line = `      ${o.name}: ${value}`;
        if (o.description) line += `   # ${o.description}`;
        lines.push(line);
      }
      return lines;
    },

    // Mirrors RecipeBlocks::commentedCatalog().
    emitBlockCatalog(L) {
      const blocks = this.availableBlocks;
      if (!blocks.length) return;
      const maxLen = Math.max(...blocks.map(b => b.id.length));
      L.push('# ── Available recipe blocks ' + '─'.repeat(54));
      L.push('# Blocks advertised by your installed recipes. Use these keys in');
      L.push('# homepage: > blocks: to compose the front page. In the future they may');
      L.push('# also drive placement for other pages or regions (sidebars, banners, etc.).');
      L.push('#');
      for (const b of blocks) {
        const pad = ' '.repeat(maxLen - b.id.length);
        L.push(`#   ${b.id}${pad}   — ${b.label}   (${b.plugin})`);
      }
      L.push('#');
      L.push('# Uncomment and edit to activate. Listed order = display order.');
      L.push('# Placed blocks are ordinary Drupal config, editable at /admin/structure/block.');
      L.push('#');
      L.push('# homepage:');
      L.push('#   blocks:');
      for (const b of blocks) {
        L.push(`#     - "${b.id}"`);
      }
      L.push('');
    },

    emitHomepage(L) {
      if (!this.homepage.length) return;
      L.push('homepage:');
      L.push('  blocks:');
      for (const id of this.homepage) {
        L.push(`    - "${id}"`);
      }
      L.push('');
    },

    emitParameters(L) {
      const design = [];
      if (this.design.primary_color) design.push(`    primary_color: ${this.q(this.design.primary_color)}`);
      if (this.design.font_family) design.push(`    font_family: ${this.q(this.design.font_family)}`);

      const grouped = {};
      for (const e of this.extras) {
        if (!e.enabled || e.value === '') continue;
        (grouped[e.config] ??= []).push(e);
      }
      const hasExtras = Object.keys(grouped).length > 0;
      if (!design.length && !hasExtras) return;

      L.push('parameters:');
      if (design.length) {
        L.push('  # Override the chosen preset\'s design tokens. Persisted to presets/overrides.css');
        L.push('  # and layered over the preset (preset <- these), so they drive utilities and');
        L.push('  # are emitted as CSS variables. Conventional keys map to Tailwind tokens:');
        L.push('  #   <name>_color -> --color-<name>  (e.g. bg-primary, text-secondary, var())');
        L.push('  #   font_family  -> --font-sans');
        L.push('  # Other keys become --<kebab> theme tokens, usable as var(--key).');
        L.push('  theme_design:');
        L.push(...design);
      }
      if (hasExtras) {
        if (design.length) L.push('');
        L.push('  # drush config:set calls applied after recipes finish.');
        L.push('  # Format: config-object-name → key (dot notation) → value');
        L.push('  recipe_config:');
        for (const [config, entries] of Object.entries(grouped)) {
          L.push(`    ${this.q(config)}:`);
          for (const e of entries) {
            L.push(`      ${e.key}: ${this.q(e.value)}`);
          }
        }
      }
      L.push('');
    },

    // ----------------------------------------------------------- actions

    async copyConfig() {
      await navigator.clipboard.writeText(this.yaml());
      this.copied = true;
      setTimeout(() => { this.copied = false; }, 1500);
    },

    downloadConfig() {
      const blob = new Blob([this.yaml()], { type: 'text/yaml' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'config.dq.yml';
      a.click();
      URL.revokeObjectURL(a.href);
    },
  };
}
