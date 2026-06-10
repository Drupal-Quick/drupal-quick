import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import { writeFileSync, unlinkSync, existsSync } from 'fs';
import { resolve } from 'path';

const MARKER_FILE = resolve(import.meta.dirname, '.vite-dev');

/**
 * Vite plugin that writes a .vite-dev marker when the dev server starts so
 * the Drupal theme PHP can detect it and load assets from the dev server.
 * The marker is removed when the server closes or a production build runs.
 */
function viteDevMarker() {
  return {
    name: 'drupal-vite-dev-marker',
    configureServer(server) {
      server.httpServer?.once('listening', () => {
        const port = server.config.server.port ?? 5173;
        writeFileSync(MARKER_FILE, String(port));
      });
      server.httpServer?.once('close', () => {
        if (existsSync(MARKER_FILE)) unlinkSync(MARKER_FILE);
      });
    },
    closeBundle() {
      if (existsSync(MARKER_FILE)) unlinkSync(MARKER_FILE);
    },
  };
}

export default defineConfig({
  plugins: [
    tailwindcss(),
    viteDevMarker(),
  ],

  build: {
    manifest: true,
    outDir: 'dist',
    emptyOutDir: true,
    rollupOptions: {
      input: {
        main: resolve(import.meta.dirname, 'src/main.js'),
      },
    },
  },

  server: {
    port: 5173,
    cors: { origin: '*' },
    // Allow Drupal (on any host) to load assets from this dev server.
    allowedHosts: 'all',
  },
});
