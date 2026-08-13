import { defineConfig } from 'vite';
import { resolve } from 'path';

export default defineConfig({
  root: 'assets',
  base: '/src/php/dist/',
  build: {
    outDir: '../dist',
    manifest: true,
    rollupOptions: {
      input: {
        main: 'js/main.ts',
      },
    },
  },
  resolve: {
    alias: {
      '@': './js',
    },
  },
  server: {
    port: 5173,
    proxy: {
      '/grid_ajax.php': 'http://localhost',
      '/ws': {
        target: 'ws://localhost',
        ws: true,
      },
    },
  },
});