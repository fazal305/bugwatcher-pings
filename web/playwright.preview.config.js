import { defineConfig, devices } from '@playwright/test'

// Runs the specs that need the production build (real hashed chunk names) against `vite preview`, with the API mocked
// in the test, so no backend is needed: npx playwright test -c playwright.preview.config.js
export default defineConfig({
  testDir: './e2e',
  testMatch: '**/*.preview.spec.js',
  timeout: 60_000,
  expect: { timeout: 15_000 },
  workers: 1,
  reporter: 'list',
  use: { baseURL: 'http://localhost:4473' },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    command: 'npx vite build && npx vite preview --port 4473 --strictPort',
    url: 'http://localhost:4473',
    timeout: 180_000,
  },
})
