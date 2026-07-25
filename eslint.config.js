import globals from 'globals';
import pluginJs from '@eslint/js';

export default [
  { ignores: ['vendor/**', 'node_modules/**', 'coverage/**', 'tests/**', '*.config.*', '*.lock', 'scripts/venv/**', 'scripts/**/site-packages/**'] },
  { files: ['**/*.js', '**/*.mjs'] },
  { languageOptions: { ecmaVersion: 2022, sourceType: 'module', globals: { ...globals.browser, ...globals.es2022 } } },
  pluginJs.configs.recommended,
  {
    rules: {
      'no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],
      'no-undef': 'off',
      'eqeqeq': ['error', 'always'],
      'prefer-const': 'error',
      'no-var': 'error'
    }
  }
];