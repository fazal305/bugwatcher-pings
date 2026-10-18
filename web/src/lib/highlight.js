const LANGUAGES = {
  php: 'php',
  phtml: 'php',
  js: 'javascript',
  mjs: 'javascript',
  cjs: 'javascript',
  jsx: 'javascript',
  ts: 'typescript',
  tsx: 'typescript',
  mts: 'typescript',
  cts: 'typescript',
}

/** Language for a frame's file (a path or a URL, possibly with a query string), or null for plain text. */
export function languageFor(file) {
  if (!file) return null
  const path = String(file).split(/[?#]/)[0]
  const match = /\.([a-z0-9]+)$/i.exec(path)
  return (match && LANGUAGES[match[1].toLowerCase()]) || null
}

let loading = null

// Only the core and three grammars, loaded the first time an event page needs them, so no other page pays for them.
export function loadHighlighter() {
  loading ??= Promise.all([
    import('highlight.js/lib/core'),
    import('highlight.js/lib/languages/php'),
    import('highlight.js/lib/languages/javascript'),
    import('highlight.js/lib/languages/typescript'),
  ]).then(([{ default: hljs }, php, javascript, typescript]) => {
    hljs.registerLanguage('php', php.default)
    hljs.registerLanguage('javascript', javascript.default)
    hljs.registerLanguage('typescript', typescript.default)
    return hljs
  })
  loading.catch(() => {
    loading = null
  })
  return loading
}

const TAG = /<span class="[^"]*">|<\/span>/g

/**
 * Highlights the lines as one block, so comments and strings that span lines are coloured correctly, then splits
 * the HTML back into lines, closing and reopening any spans that cross a line break. highlight.js escapes the
 * source text itself; its output contains no markup other than these spans.
 */
export function highlightLines(hljs, lines, language) {
  const source = lines.map((line) => line.replace(/\r\n|\r|\n/g, ' ')).join('\n')
  const html = hljs.highlight(source, { language, ignoreIllegals: true }).value
  const open = []
  return html.split('\n').map((line) => {
    const prefix = open.join('')
    for (const [tag] of line.matchAll(TAG)) {
      if (tag === '</span>') open.pop()
      else open.push(tag)
    }
    return prefix + line + '</span>'.repeat(open.length)
  })
}
