// ESLint 9 (flat config) — ajouté avec PHPStan + Pint dans la CI
// (docs/TODOS.md « CI Linter Debt »). Voir .github/workflows/ci.yml.
import js from '@eslint/js'
import globals from 'globals'
import react from 'eslint-plugin-react'
import reactHooks from 'eslint-plugin-react-hooks'
import reactRefresh from 'eslint-plugin-react-refresh'

export default [
  {
    ignores: [
      'dist/**',
      'node_modules/**',
      '_old_dist_*/**',
      'public/**',
    ],
  },
  js.configs.recommended,
  {
    // Application (React 19 + JSX transform de Vite : pas d'import React).
    files: ['src/**/*.{js,jsx}'],
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      globals: globals.browser,
      parserOptions: {
        ecmaFeatures: { jsx: true },
      },
    },
    settings: { react: { version: 'detect' } },
    plugins: {
      react,
      'react-hooks': reactHooks,
      'react-refresh': reactRefresh,
    },
    rules: {
      ...react.configs.recommended.rules,
      ...reactHooks.configs.recommended.rules,
      // JSX transform automatique (vite/react) : React n'est pas importé.
      'react/react-in-jsx-scope': 'off',
      'react/jsx-uses-react': 'off',
      // Pas de propTypes dans ce projet (types documentés en JSDoc).
      'react/prop-types': 'off',
      // UI en français : l'apostrophe droite est partout dans le JSX,
      // l'échapper dégraderait la lisibilité sans bénéfice.
      'react/no-unescaped-entities': 'off',
      // 14 motifs historiques (setState dans un effet) : à corriger case par
      // case, trop risqué pour être bloquant en CI — remonté en warning.
      'react-hooks/set-state-in-effect': 'warn',
      'react-refresh/only-export-components': [
        'warn',
        { allowConstantExport: true },
      ],
    },
  },
  {
    // Config Vite + scripts Node (globals.node, pas de JSX).
    files: ['vite.config.js', 'scripts/**/*.{js,mjs}'],
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      globals: globals.node,
    },
  },
]
