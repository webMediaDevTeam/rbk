import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import path from 'node:path'
import { defineConfig } from 'vite'

export default defineConfig({
  // Les images téléversées (avatars, logos) sont rendues par l'API sous un chemin
  // **relatif** (`/storage/…`) : le navigateur les demande donc sur sa propre
  // origine, `:5173` en dev. Encore faut-il que le serveur de dev sache où les
  // chercher — sans ce proxy, `/storage/...` retombe sur le repli SPA de Vite et
  // renvoie `index.html` : l'`<img>` ne casse pas en 404, elle affiche du HTML.
  //
  // Cible lue dans `process.env` (et non dans l'argument `env` du format fonction
  // de `defineConfig` : `env` est `undefined` avec ce build de Vite, et la
  // lecture de `VITE_STORAGE_PROXY_TARGET` faisait planter le serveur de dev).
  // Compose injecte cette variable dans le conteneur `frontend` ; le nom de
  // service docker (`http://backend-dev:8000`) est résolu depuis ce processus,
  // alors que `VITE_API_URL` (`localhost`) ne vise que le navigateur. Le repli
  // `localhost:8000` correspond au `npm run dev` lancé hors conteneur.
  server: {
    proxy: {
      '/storage': {
        target: process.env.VITE_STORAGE_PROXY_TARGET ?? 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': path.resolve(import.meta.dirname, './src'),
    },
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    assetsDir: 'build',
  },
  base: '/',
})
