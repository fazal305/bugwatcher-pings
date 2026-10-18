import { spawn } from 'node:child_process'

// queue:work prints nothing until it handles a job, so this gives Playwright a line to wait for.
const worker = spawn(process.env.PHP_BINARY || 'php', ['../api/artisan', 'queue:work', 'redis', '--queue=webhooks,ingest,default', '--sleep=0'], {
  stdio: 'inherit',
})
console.log('Queue worker started')
worker.on('exit', (code) => process.exit(code ?? 0))
