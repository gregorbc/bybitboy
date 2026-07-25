import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    environment: 'jsdom',
    globals: true,
    include: ['tests/js/**/*.test.ts'],
    coverage: {
      provider: 'v8',
      reporter: ['text', 'json', 'html'],
      exclude: [
        'node_modules/',
        'vendor/',
        'tests/',
        '*.config.*',
        'scripts/venv/**',
        'scripts/**/site-packages/**'
      ]
    }
  }
});