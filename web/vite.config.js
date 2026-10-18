import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { defineConfig } from 'vite'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: { port: 5473, strictPort: true },
  // Inlined fonts would be data: URLs, which the Content-Security-Policy in vercel.json (font-src 'self') blocks.
  build: { assetsInlineLimit: (file) => (file.endsWith('.woff2') ? false : undefined) },
})
