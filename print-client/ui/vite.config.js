import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath } from 'node:url'

// Tauri expects a fixed dev port and a dist folder next to src-tauri (see tauri.conf.json).
export default defineConfig({
    root: fileURLToPath(new URL('.', import.meta.url)),
    plugins: [vue()],
    clearScreen: false,
    server: { port: 1420, strictPort: true },
    build: { outDir: 'dist', emptyOutDir: true, target: 'es2021' },
})
