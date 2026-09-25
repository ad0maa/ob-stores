import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// No dev server: PHP serves the page and reads .vite/manifest.json to find
// the hashed bundle. `npm run watch` rebuilds on save.
export default defineConfig({
  plugins: [vue()],
  publicDir: false,
  build: {
    outDir: 'public/build',
    emptyOutDir: true,
    manifest: true,
    rolldownOptions: {
      input: 'frontend/planner/main.js',
    },
  },
})
