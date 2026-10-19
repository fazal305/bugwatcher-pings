import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';

const sent = [];
let fresh = 0;

globalThis.window = new EventTarget();
globalThis.location = { href: 'https://app.test/page' };
Object.defineProperty(globalThis, 'navigator', { value: { userAgent: 'node-test' }, configurable: true });
globalThis.fetch = async (url, options) => {
  sent.push(JSON.parse(options.body));
  return { ok: true };
};

beforeEach(() => {
  sent.length = 0;
  globalThis.window = new EventTarget();
});

// A query string gives each test its own copy of the module state.
const load = () => import(`./bugwatcher.js?case=${++fresh}`);
const base = { key: 'bwp_pk_test', endpoint: 'https://api.test/api/v1/ping' };

function fireError(message) {
  const event = new Event('error', { cancelable: true });
  Object.assign(event, { error: new Error(message), message, filename: 'https://app.test/app.js', lineno: 1 });
  window.dispatchEvent(event);
  return event;
}

function fireRejection(reason) {
  const event = new Event('unhandledrejection', { cancelable: true });
  event.reason = reason;
  window.dispatchEvent(event);
  return event;
}

test('stops sending after maxReports', async () => {
  const sdk = await load();
  sdk.init({ ...base, maxReports: 3 });
  for (let i = 0; i < 10; i++) fireError(`boom ${i}`);
  assert.equal(sent.length, 3);
  assert.deepEqual(sent.map((p) => p.message), ['boom 0', 'boom 1', 'boom 2']);
});

test('unhandled rejections and captureException share the budget', async () => {
  const sdk = await load();
  sdk.init({ ...base, maxReports: 2 });
  fireRejection(new Error('rejected'));
  sdk.captureException(new Error('manual 1'));
  sdk.captureException(new Error('manual 2'));
  fireError('late');
  assert.deepEqual(sent.map((p) => p.message), ['rejected', 'manual 1']);
});

test('deduplicated repeats do not use up the budget', async () => {
  const sdk = await load();
  sdk.init({ ...base, maxReports: 2 });
  const error = new Error('same');
  for (let i = 0; i < 5; i++) sdk.captureException(error);
  sdk.captureException(new Error('different'));
  assert.deepEqual(sent.map((p) => p.message), ['same', 'different']);
});

test('failed sends still count', async () => {
  const realFetch = globalThis.fetch;
  let attempts = 0;
  globalThis.fetch = () => {
    attempts++;
    throw new Error('offline');
  };
  try {
    const sdk = await load();
    sdk.init({ ...base, maxReports: 2 });
    for (let i = 0; i < 5; i++) sdk.captureException(new Error(`e${i}`));
    assert.equal(attempts, 2);
  } finally {
    globalThis.fetch = realFetch;
  }
});

test('unlimited when maxReports is omitted', async () => {
  const sdk = await load();
  sdk.init(base);
  for (let i = 0; i < 50; i++) sdk.captureException(new Error(`e${i}`));
  assert.equal(sent.length, 50);
});

for (const value of [0, -5, 2.5, '10', NaN, Infinity, null]) {
  test(`invalid maxReports (${typeof value} ${String(value)}) falls back to unlimited`, async () => {
    const sdk = await load();
    assert.doesNotThrow(() => sdk.init({ ...base, maxReports: value }));
    for (let i = 0; i < 30; i++) sdk.captureException(new Error(`e${i}`));
    assert.equal(sent.length, 30);
  });
}

test("the app's own listeners keep running after the cap", async () => {
  const sdk = await load();
  const seen = { before: 0, after: 0, rejections: 0 };
  window.addEventListener('error', () => seen.before++);
  sdk.init({ ...base, maxReports: 2 });
  window.addEventListener('error', () => seen.after++);
  window.addEventListener('unhandledrejection', () => seen.rejections++);

  const events = [];
  assert.doesNotThrow(() => {
    for (let i = 0; i < 25; i++) events.push(fireError(`boom ${i}`));
    for (let i = 0; i < 5; i++) events.push(fireRejection(new Error(`r${i}`)));
  });

  assert.equal(sent.length, 2);
  assert.deepEqual(seen, { before: 25, after: 25, rejections: 5 });
  assert.ok(events.every((event) => !event.defaultPrevented));
});

test('does not touch window.onerror', async () => {
  const sdk = await load();
  const handler = () => {};
  window.onerror = handler;
  sdk.init({ ...base, maxReports: 1 });
  assert.equal(window.onerror, handler);
});
