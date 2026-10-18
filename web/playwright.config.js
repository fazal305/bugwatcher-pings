import { defineConfig, devices } from '@playwright/test'

const PHP = process.env.PHP_BINARY || 'php'
const ARTISAN = '../api/artisan'

// Runs against the full local stack: PostgreSQL and Redis from `docker compose up -d`, the API with api/.env, a queue
// worker and Reverb, so a test error really goes through the queue and arrives over WebSockets. Servers that are
// already running (for example from `npm run dev`) are reused.
export default defineConfig({
  testDir: './e2e',
  timeout: 60_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  reporter: 'list',
  globalSetup: './e2e/global-setup.js',
  use: {
    baseURL: 'http://localhost:5473',
    trace: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: [
    {
      command: `${PHP} ${ARTISAN} serve --port=8473`,
      url: 'http://127.0.0.1:8473/api/health',
      reuseExistingServer: true,
    },
    {
      command: `${PHP} ${ARTISAN} reverb:start`,
      port: 8080,
      reuseExistingServer: true,
    },
    {
      command: 'node e2e/queue-worker.js',
      wait: { stdout: /Queue worker started/ },
    },
    {
      command: 'npm run dev',
      url: 'http://localhost:5473',
      reuseExistingServer: true,
    },
  ],
})
