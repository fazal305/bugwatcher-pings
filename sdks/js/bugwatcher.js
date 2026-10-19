// BugWatcher Pings browser SDK. No dependencies; every public function swallows its own errors.

const SDK = { name: 'bugwatcher-js', version: '0.1.0' };
const DEDUPE_MS = 1000;
const MAX_FRAMES = 50;

let config = null;
let sentCount = 0;
const recent = new Map();

// Chrome/Edge/Node: "    at fn (https://x/app.js:10:5)" or "    at https://x/app.js:10:5"
const CHROME_LINE = /^\s*at (?:(.+?) \()?((?:[a-z][\w+.-]*:|\/).*?):(\d+)(?::(\d+))?\)?\s*$/i;
// Firefox/Safari: "fn@https://x/app.js:10:5"
const GECKO_LINE = /^\s*(.*?)@(.+?):(\d+)(?::(\d+))?\s*$/;

export function parseStack(stack) {
  const frames = [];
  for (const line of String(stack || '').split('\n')) {
    const match = CHROME_LINE.exec(line) || GECKO_LINE.exec(line);
    if (!match) continue;
    frames.push({
      function: match[1] || '<anonymous>',
      file: match[2],
      line: Number(match[3]),
      in_app: !/\/node_modules\//.test(match[2]),
    });
    if (frames.length >= MAX_FRAMES) break;
  }
  return frames;
}

function buildPing(error, level, extra) {
  const err = error instanceof Error ? error : new Error(typeof error === 'string' ? error : safeString(error));
  const frames = parseStack(err.stack);
  if (frames.length === 0 && extra && extra.file) {
    frames.push({ file: extra.file, line: extra.line || 0, function: '<anonymous>', in_app: true });
  }

  return {
    message: String(err.message || err.name || 'Error').slice(0, 2000),
    level,
    environment: config.environment,
    timestamp: Date.now(),
    release: config.release || undefined,
    exception: { type: err.name || 'Error', frames },
    request: { method: 'GET', url: location.href, headers: { 'User-Agent': navigator.userAgent } },
    user: config.user ? { id: String(config.user.id) } : undefined,
    sdk: SDK,
  };
}

function safeString(value) {
  try {
    return JSON.stringify(value);
  } catch {
    return String(value);
  }
}

function isDuplicate(ping) {
  const top = ping.exception.frames[0];
  const signature = [ping.exception.type, ping.message, top && top.file, top && top.line].join('|');
  const now = Date.now();
  for (const [key, at] of recent) {
    if (now - at > DEDUPE_MS) recent.delete(key);
  }
  if (recent.has(signature)) return true;
  recent.set(signature, now);
  return false;
}

function send(ping) {
  const body = JSON.stringify(ping);
  try {
    fetch(config.endpoint, {
      method: 'POST',
      keepalive: true,
      headers: { 'Content-Type': 'application/json', 'X-BugWatcher-Key': config.key },
      body,
    }).catch(() => beacon(ping));
  } catch {
    beacon(ping);
  }
}

// sendBeacon can't set headers, so the key travels in the body; text/plain avoids a CORS preflight.
function beacon(ping) {
  try {
    if (navigator.sendBeacon) {
      navigator.sendBeacon(config.endpoint, new Blob([JSON.stringify({ ...ping, key: config.key })], { type: 'text/plain' }));
    }
  } catch {
    // Reporting must never break the page.
  }
}

export function captureException(error, { level = 'error', ...extra } = {}) {
  try {
    if (!config || sentCount >= config.maxReports) return;
    const ping = buildPing(error, level, extra);
    if (isDuplicate(ping)) return;
    sentCount++;
    send(ping);
  } catch {
    // Reporting must never break the page.
  }
}

export function setUser(user) {
  if (config) config.user = user && user.id != null ? { id: user.id } : null;
}

export function init({ key, endpoint, environment = 'production', release, maxReports } = {}) {
  try {
    if (!key || !endpoint || typeof window === 'undefined') return;
    const first = config === null;
    config = {
      key,
      endpoint,
      environment,
      release,
      user: null,
      maxReports: Number.isInteger(maxReports) && maxReports > 0 ? maxReports : Infinity,
    };
    if (!first) return;

    // Listeners only observe: no preventDefault or stopPropagation, so the app's own handlers always run.
    window.addEventListener('error', (event) => {
      captureException(event.error || event.message, { file: event.filename, line: event.lineno });
    });
    window.addEventListener('unhandledrejection', (event) => {
      captureException(event.reason);
    });
  } catch {
    // Reporting must never break the page.
  }
}
