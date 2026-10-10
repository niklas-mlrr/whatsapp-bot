/**
 * WhatsApp text formatting: safe HTML rendering for message bubbles and
 * pure editing helpers for the composer toolbar / keyboard shortcuts.
 *
 * Supported syntax (same as WhatsApp; there is no underline):
 *   *bold*  _italic_  ~strike~  `inline code`  ```monospace block```
 *   "> " quote, "- " / "* " bullet list, "1. " numbered list
 */

export interface TextEdit {
  text: string
  selStart: number
  selEnd: number
}

export type InlineFormat = 'bold' | 'italic' | 'strike' | 'code' | 'mono'
export type LineFormat = 'quote' | 'bullet' | 'numbered'

export const INLINE_MARKERS: Record<InlineFormat, string> = {
  bold: '*',
  italic: '_',
  strike: '~',
  code: '`',
  mono: '```',
}

// Placeholder delimiters for already-rendered fragments (links, inline code).
// Control characters never appear in normal text and are stripped from input.
const PH_START = '\u0001'
const PH_END = '\u0002'

const URL_REGEX =
  /((https?:\/\/|www\.)[^\s<\u0001\u0002]+|(?:(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,}))(?:\/[^\s<\u0001\u0002]*)?)/gi

export function escapeHtml(s: string): string {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

function escapeRegex(s: string): string {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

// WhatsApp rule: opening marker at start or after a non-alphanumeric char and
// directly followed by non-space; closing marker mirrored. Keeps snake_case and
// 2*3*4 untouched.
function applyMarker(s: string, marker: string, tag: string): string {
  const m = escapeRegex(marker)
  const re = new RegExp(
    `(^|[^\\p{L}\\p{N}${m}])${m}(?![\\s${m}])(.+?)(?<![\\s${m}])${m}(?=$|[^\\p{L}\\p{N}${m}])`,
    'gu',
  )
  return s.replace(re, (_match, pre: string, inner: string) => `${pre}<${tag}>${inner}</${tag}>`)
}

function renderInline(line: string): string {
  const fragments: string[] = []
  const hold = (html: string) => {
    fragments.push(html)
    return `${PH_START}${fragments.length - 1}${PH_END}`
  }

  // 1) inline code (content is not formatted further)
  let s = line.replace(/`([^`]+)`/g, (_m, inner: string) => hold(`<code class="wa-code">${escapeHtml(inner)}</code>`))

  // 2) links
  s = s.replace(URL_REGEX, (match: string, _g1: string, _g2: string, offset: number, whole: string) => {
    const prevChar = offset > 0 ? whole[offset - 1] : ''
    // Don't linkify emails like test@example.com
    if (prevChar === '@' || match.includes('@')) return match
    let url = match
    let trailing = ''
    while (url.length > 0 && /[)\]}.,!?:;*_~'"]$/.test(url)) {
      trailing = url.slice(-1) + trailing
      url = url.slice(0, -1)
    }
    if (!url) return match
    const href = /^https?:\/\//i.test(url) ? url : `https://${url}`
    return (
      hold(
        `<a href="${escapeHtml(href)}" target="_blank" rel="noopener noreferrer" class="text-blue-600 dark:text-blue-400 hover:underline break-all">${escapeHtml(url)}</a>`,
      ) + trailing
    )
  })

  // 3) escape everything else, then apply text styles
  s = escapeHtml(s)
  s = applyMarker(s, '~', 'del')
  s = applyMarker(s, '*', 'strong')
  s = applyMarker(s, '_', 'em')

  // 4) restore held fragments
  return s.replace(new RegExp(`${PH_START}(\\d+)${PH_END}`, 'g'), (_m, i: string) => fragments[Number(i)] ?? '')
}

type LineKind = 'plain' | 'quote' | 'bullet' | 'numbered'

function classifyLine(line: string): { kind: LineKind; body: string; num?: string } {
  let m = line.match(/^>\s(.*)$/)
  if (m) return { kind: 'quote', body: m[1] ?? '' }
  m = line.match(/^[-*]\s+(.*)$/)
  if (m) return { kind: 'bullet', body: m[1] ?? '' }
  m = line.match(/^(\d+)\.\s+(.*)$/)
  if (m) return { kind: 'numbered', body: m[2] ?? '', num: m[1] }
  return { kind: 'plain', body: line }
}

function renderLines(text: string): string {
  const lines = text.split('\n')
  const out: string[] = []
  let i = 0
  while (i < lines.length) {
    const first = classifyLine(lines[i] ?? '')
    const group: { body: string; num?: string }[] = []
    while (i < lines.length) {
      const c = classifyLine(lines[i] ?? '')
      if (c.kind !== first.kind) break
      group.push(c)
      i++
    }
    if (first.kind === 'plain') {
      out.push(group.map((g) => renderInline(g.body)).join('<br/>'))
    } else if (first.kind === 'quote') {
      out.push(`<span class="wa-quote">${group.map((g) => renderInline(g.body)).join('<br/>')}</span>`)
    } else {
      const items = group
        .map((g) => {
          const marker = first.kind === 'numbered' ? `${g.num}.` : '•'
          return `<span class="wa-li"><span class="wa-li-marker">${marker}</span><span>${renderInline(g.body)}</span></span>`
        })
        .join('')
      out.push(`<span class="wa-list">${items}</span>`)
    }
  }
  return out.join('')
}

/**
 * Render a WhatsApp message text as safe HTML (all user text is escaped).
 */
export function renderWhatsAppText(raw?: string | null): string {
  if (!raw || typeof raw !== 'string') return ''
  // Legacy: older messages stored literal <br> for line breaks
  const normalized = raw
    .replace(/<br\s*\/?\s*>/gi, '\n')
    .replace(/\r\n?/g, '\n')
    .replace(/[\u0001\u0002]/g, '')

  const parts = normalized.split(/```([\s\S]+?)```/)
  let html = ''
  parts.forEach((part, idx) => {
    if (idx % 2 === 1) {
      const code = part.replace(/^\n/, '').replace(/\n$/, '')
      html += `<span class="wa-pre">${escapeHtml(code)}</span>`
    } else if (part) {
      // Drop the line break directly around a code block; the block itself breaks the line
      let text = part
      if (idx > 0) text = text.replace(/^\n/, '')
      if (idx < parts.length - 1) text = text.replace(/\n$/, '')
      if (text) html += renderLines(text)
    }
  })
  return html
}

/** True when the text contains anything WhatsApp would render as formatting. */
export function hasWhatsAppFormatting(raw?: string | null): boolean {
  if (!raw) return false
  return /<(strong|em|del|code)\b|class="wa-(pre|quote|list)"/.test(renderWhatsAppText(raw))
}

/* ------------------------------------------------------------------ */
/* Editing helpers (pure, return the new text + selection)             */
/* ------------------------------------------------------------------ */

function splitWhitespace(s: string): { lead: string; core: string; trail: string } {
  const lead = s.match(/^\s*/)?.[0] ?? ''
  const rest = s.slice(lead.length)
  const trail = rest.match(/\s*$/)?.[0] ?? ''
  return { lead, core: rest.slice(0, rest.length - trail.length), trail }
}

function isWrapped(core: string, marker: string): boolean {
  return core.length > marker.length * 2 && core.startsWith(marker) && core.endsWith(marker)
}

/**
 * Toggle an inline style on the current selection.
 * - no selection: inserts the marker pair and puts the cursor between them
 *   (or removes an empty pair the cursor is sitting in)
 * - selection: trims surrounding whitespace so markers hug the text, then wraps;
 *   if the selection is already wrapped (inside or directly outside) it unwraps
 * - multi-line selection: each line is toggled separately (except ``` blocks)
 */
export function toggleInlineFormat(text: string, selStart: number, selEnd: number, format: InlineFormat): TextEdit {
  const marker = INLINE_MARKERS[format]
  const len = marker.length
  let start = Math.min(selStart, selEnd)
  let end = Math.max(selStart, selEnd)

  // Trim selection to the non-whitespace core
  while (start < end && /\s/.test(text[start] ?? '')) start++
  while (end > start && /\s/.test(text[end - 1] ?? '')) end--

  if (start === end) {
    // Cursor inside an empty pair -> remove it
    if (text.slice(start - len, start) === marker && text.slice(start, start + len) === marker) {
      return { text: text.slice(0, start - len) + text.slice(start + len), selStart: start - len, selEnd: start - len }
    }
    // Cursor at the end of a formatted run ("_kursiv|_") -> step out past the closing marker
    if (text.slice(start, start + len) === marker && !/\s/.test(text[start - 1] ?? ' ')) {
      const lineStart = text.lastIndexOf('\n', start - 1) + 1
      const open = text.slice(lineStart, start).lastIndexOf(marker)
      if (open !== -1 && lineStart + open + len < start) {
        return { text, selStart: start + len, selEnd: start + len }
      }
    }
    const insert = format === 'mono' ? '``````' : marker + marker
    return { text: text.slice(0, start) + insert + text.slice(start), selStart: start + len, selEnd: start + len }
  }

  const selected = text.slice(start, end)

  // Markers directly outside the selection -> unwrap
  if (text.slice(start - len, start) === marker && text.slice(end, end + len) === marker) {
    return {
      text: text.slice(0, start - len) + selected + text.slice(end + len),
      selStart: start - len,
      selEnd: end - len,
    }
  }

  if (format === 'mono' || !selected.includes('\n')) {
    if (isWrapped(selected, marker)) {
      const inner = selected.slice(len, selected.length - len)
      return { text: text.slice(0, start) + inner + text.slice(end), selStart: start, selEnd: start + inner.length }
    }
    return {
      text: text.slice(0, start) + marker + selected + marker + text.slice(end),
      selStart: start + len,
      selEnd: end + len,
    }
  }

  // Multi-line: toggle per line
  const lines = selected.split('\n').map(splitWhitespace)
  const nonEmpty = lines.filter((l) => l.core)
  const unwrap = nonEmpty.length > 0 && nonEmpty.every((l) => isWrapped(l.core, marker))
  const replaced = lines
    .map(({ lead, core, trail }) => {
      if (!core) return lead + trail
      const c = unwrap ? core.slice(len, core.length - len) : marker + core + marker
      return lead + c + trail
    })
    .join('\n')
  return { text: text.slice(0, start) + replaced + text.slice(end), selStart: start, selEnd: start + replaced.length }
}

const LINE_PREFIX_RE: Record<LineFormat, RegExp> = {
  quote: /^> /,
  bullet: /^[-*] /,
  numbered: /^\d+\. /,
}
const ANY_PREFIX_RE = /^(> |[-*] |\d+\. )/

/**
 * Toggle a line prefix (quote / bullet / numbered list) on every line touched
 * by the selection. Switching between list types replaces the old prefix.
 */
export function toggleLinePrefix(text: string, selStart: number, selEnd: number, format: LineFormat): TextEdit {
  const start = Math.min(selStart, selEnd)
  let end = Math.max(selStart, selEnd)
  // A selection ending right after a newline should not include the next line
  if (end > start && text[end - 1] === '\n') end--

  const lineStart = text.lastIndexOf('\n', start - 1) + 1
  const nl = text.indexOf('\n', end)
  const lineEnd = nl === -1 ? text.length : nl

  const lines = text.slice(lineStart, lineEnd).split('\n')
  const targets = lines.some((l) => l.trim()) ? lines.map((l) => !!l.trim()) : lines.map(() => true)
  const remove = lines.every((l, i) => !targets[i] || LINE_PREFIX_RE[format].test(l))

  let n = 0
  const replaced = lines
    .map((l, i) => {
      if (!targets[i]) return l
      if (remove) return l.replace(LINE_PREFIX_RE[format], '')
      const body = l.replace(ANY_PREFIX_RE, '')
      n++
      const prefix = format === 'quote' ? '> ' : format === 'bullet' ? '- ' : `${n}. `
      return prefix + body
    })
    .join('\n')

  const newText = text.slice(0, lineStart) + replaced + text.slice(lineEnd)
  if (selStart === selEnd && lines.length === 1) {
    const caret = lineStart + replaced.length
    return { text: newText, selStart: caret, selEnd: caret }
  }
  return { text: newText, selStart: lineStart, selEnd: lineStart + replaced.length }
}
