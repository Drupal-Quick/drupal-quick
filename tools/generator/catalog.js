/**
 * Static catalog for the config.dq.yml generator POC.
 *
 * Hardcoded mirror of what the recipe registry + starterkit package.json will
 * eventually provide dynamically (likely emitted by bin/dq-registry-build as a
 * JSON blob both the PHP injectors and this generator consume). Preset token
 * values are transcribed from dq-starterkit/presets/*.css so the preview pane
 * renders with the real design tokens.
 *
 * @todo Dummy content should eventually ship inside each recipe (a
 *   sample-content metadata block) so the generator — and optionally the
 *   scaffold itself — can populate from the recipe rather than from here.
 */
window.DQ_CATALOG = {

  presets: [
    {
      key: 'minimal',
      label: 'Minimal',
      description: 'Restrained editorial defaults — ink on paper, Inter.',
      tokens: {
        primary: '#111827',
        secondary: '#4b5563',
        accent: '#374151',
        paper: 'oklch(0.99 0.002 80)',
        ink: 'oklch(0.22 0.004 80)',
        muted: 'oklch(0.55 0.004 80)',
        rule: 'oklch(0.9 0.003 80)',
        font: "'Inter', ui-sans-serif, system-ui, sans-serif",
        title: '1rem', titleLh: '1.5rem',
        meta: '0.875rem', metaLh: '1.25rem',
        flow: '1.5rem', row: '0.5rem',
      },
    },
    {
      key: 'corporate',
      label: 'Corporate',
      description: 'Deep blue + teal, business-forward. Dense scale.',
      tokens: {
        primary: '#1e3a8a',
        secondary: '#0d9488',
        accent: '#f59e0b',
        paper: 'oklch(0.99 0.002 80)',
        ink: 'oklch(0.22 0.004 80)',
        muted: 'oklch(0.55 0.004 80)',
        rule: 'oklch(0.9 0.003 80)',
        font: "'Helvetica Neue', Arial, sans-serif",
        title: '1rem', titleLh: '1.5rem',
        meta: '0.875rem', metaLh: '1.25rem',
        flow: '1.25rem', row: '0.5rem',
      },
    },
    {
      key: 'geometric',
      label: 'Geometric',
      description: 'Geom (self-hosted webfont) with an indigo/pink accent. Airy scale.',
      tokens: {
        primary: '#0f172a',
        secondary: '#6366f1',
        accent: '#ec4899',
        paper: 'oklch(0.99 0.002 80)',
        ink: 'oklch(0.22 0.004 80)',
        muted: 'oklch(0.55 0.004 80)',
        rule: 'oklch(0.9 0.003 80)',
        // Geom itself is fetched on demand at build time; the preview
        // approximates with a geometric system stack.
        font: "'Geom', 'Futura', 'Century Gothic', ui-sans-serif, sans-serif",
        title: '1.125rem', titleLh: '1.75rem',
        meta: '0.875rem', metaLh: '1.25rem',
        flow: '2rem', row: '0.75rem',
      },
    },
  ],

  recipes: [
    {
      key: 'blog',
      label: 'Blog',
      description: 'Keywords taxonomy + entity reference field on Article, and a "writing" view listing articles by date at /writing (set as the front page).',
      options: [
        {
          name: 'items_per_page',
          type: 'integer',
          description: 'How many articles the writing view lists per page.',
          default: 30,
        },
      ],
      blocks: [
        { key: 'recent', label: 'Recent writing', plugin: 'views_block:writing-block_1' },
      ],
      dummy: {
        articles: [
          { title: 'On shipping small', date: 'Jun 28, 2026' },
          { title: 'Recipes as composition units', date: 'Jun 21, 2026' },
          { title: 'A field guide to design tokens', date: 'Jun 14, 2026' },
          { title: 'Static first, dynamic when earned', date: 'Jun 02, 2026' },
          { title: 'Notes on the writing view', date: 'May 25, 2026' },
        ],
      },
    },
    {
      key: 'project',
      label: 'Project',
      description: 'A Project content type (basic page + link + thumbnail) and a "projects" view listing them as a thumbnail grid.',
      options: [],
      blocks: [
        { key: 'grid', label: 'Projects grid', plugin: 'views_block:projects-block_1' },
      ],
      dummy: {
        projects: [
          { title: 'Wayfarer', blurb: 'Trail maps for the impatient.' },
          { title: 'Ledger Lite', blurb: 'Plain-text accounting, visualized.' },
          { title: 'Fieldnotes', blurb: 'A pocket wiki for research trips.' },
          { title: 'Beacon', blurb: 'Uptime checks with taste.' },
          { title: 'Drift', blurb: 'Ambient soundscapes, generated.' },
          { title: 'Quickthe.me', blurb: 'This very generator, one day.' },
        ],
      },
    },
  ],

  // Curated global config overrides (parameters: -> recipe_config). Config
  // scoped to one recipe belongs in that recipe's options; these are
  // site-wide values worth surfacing.
  extras: [
    {
      id: 'slogan',
      config: 'system.site',
      key: 'slogan',
      label: 'Site slogan',
      description: 'Shown wherever the theme prints the site slogan.',
      sample: 'Scaffolded quickly, maintained sustainably.',
    },
    {
      id: 'mail',
      config: 'system.site',
      key: 'mail',
      label: 'Site email address',
      description: 'The from-address for automated site mail.',
      sample: 'hello@example.com',
    },
    {
      id: 'register',
      config: 'user.settings',
      key: 'register',
      label: 'Account registration',
      description: 'Who can create accounts: visitors | admin_only | visitors_admin_approval',
      sample: 'admin_only',
    },
  ],

  // Font choices offered for the theme_design override (free text also works
  // in the real config; this is the curated POC list).
  fonts: [
    { value: '', label: 'No override (use preset font)' },
    { value: "'Inter', sans-serif", label: 'Inter' },
    { value: "'Helvetica Neue', Arial, sans-serif", label: 'Helvetica Neue' },
    { value: "'Geom', sans-serif", label: 'Geom' },
    { value: 'Georgia, serif', label: 'Georgia (serif)' },
    { value: 'ui-monospace, monospace', label: 'Monospace' },
  ],
};
