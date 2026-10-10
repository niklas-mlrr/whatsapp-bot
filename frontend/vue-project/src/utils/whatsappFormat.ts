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

export interface ParsedLine {
  /** Leading whitespace (indentation) */
  indent: string
  kind: LineKind
  /** List/quote marker incl. trailing space, e.g. "- ", "3. ", "> " ('' for plain) */
  marker: string
  body: string
  num?: number
}

/** Spaces per indentation level when indenting from the composer */
export const INDENT_UNIT = '    '

const LINE_RE = /^([ \t]*)(?:(> )|([-*] +)|((\d+)\. +))?(.*)$/

export function parseLine(line: string): ParsedLine {
  const m = line.match(LINE_RE)
  const indent = m?.[1] ?? ''
  const body = m?.[6] ?? line
  if (m?.[2]) return { indent, kind: 'quote', marker: m[2], body }
  if (m?.[3]) return { indent, kind: 'bullet', marker: m[3], body }
  if (m?.[4]) return { indent, kind: 'numbered', marker: m[4], body, num: Number(m[5]) }
  return { indent, kind: 'plain', marker: '', body: line.slice(indent.length) }
}

/** Indentation width in spaces (a tab counts as one indentation level) */
function indentWidth(indent: string): number {
  let w = 0
  for (const ch of indent) w += ch === '\t' ? INDENT_UNIT.length : 1
  return w
}

// 1.5em per indentation level (INDENT_UNIT spaces)
function indentEm(indent: string): string {
  return `${((indentWidth(indent) / INDENT_UNIT.length) * 1.5).toFixed(2)}em`
}

function indentSpacer(indent: string): string {
  return indent ? `<span class="wa-indent" style="width:${indentEm(indent)}"></span>` : ''
}

function renderLines(text: string): string {
  const lines = text.split('\n').map(parseLine)
  const isList = (l: ParsedLine) => l.kind === 'bullet' || l.kind === 'numbered'
  const out: string[] = []
  let i = 0
  while (i < lines.length) {
    const first = lines[i]!
    const group: ParsedLine[] = []
    while (i < lines.length) {
      const l = lines[i]!
      const sameGroup = isList(first) ? isList(l) : l.kind === first.kind
      if (!sameGroup) break
      group.push(l)
      i++
    }
    if (first.kind === 'plain') {
      out.push(group.map((g) => indentSpacer(g.indent) + renderInline(g.body)).join('<br/>'))
    } else if (first.kind === 'quote') {
      const inner = group.map((g) => indentSpacer(g.indent) + renderInline(g.body)).join('<br/>')
      out.push(`<span class="wa-quote">${inner}</span>`)
    } else {
      // Markers of one indentation level share a width so text lines up (also for 9. -> 10.)
      const markerText = (g: ParsedLine) => (g.kind === 'numbered' ? `${g.num}.` : '•')
      const widthByIndent = new Map<number, number>()
      for (const g of group) {
        const w = indentWidth(g.indent)
        widthByIndent.set(w, Math.max(widthByIndent.get(w) ?? 1, markerText(g).length))
      }
      const items = group
        .map((g) => {
          const pad = g.indent ? ` style="padding-left:${indentEm(g.indent)}"` : ''
          const minW = widthByIndent.get(indentWidth(g.indent)) ?? 1
          return (
            `<span class="wa-li"${pad}><span class="wa-li-marker" style="min-width:${minW}ch">${markerText(g)}</span>` +
            `<span>${renderInline(g.body)}</span></span>`
          )
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

/** Range of whole lines touched by a selection: [lineStart, lineEnd) */
function touchedLines(text: string, selStart: number, selEnd: number): { lineStart: number; lineEnd: number } {
  const start = Math.min(selStart, selEnd)
  let end = Math.max(selStart, selEnd)
  // A selection ending right after a newline should not include the next line
  if (end > start && text[end - 1] === '\n') end--
  const lineStart = text.lastIndexOf('\n', start - 1) + 1
  const nl = text.indexOf('\n', end)
  return { lineStart, lineEnd: nl === -1 ? text.length : nl }
}

/** Offset of the n-th line (0-based) in text */
function lineOffset(text: string, lineIndex: number): number {
  let pos = 0
  for (let i = 0; i < lineIndex; i++) {
    const nl = text.indexOf('\n', pos)
    if (nl === -1) return text.length
    pos = nl + 1
  }
  return pos
}

function lineIndexAt(text: string, offset: number): number {
  let n = 0
  for (let i = 0; i < offset; i++) if (text[i] === '\n') n++
  return n
}

function formatLine(l: ParsedLine): string {
  return l.indent + l.marker + l.body
}

/**
 * Renumber numbered lists: within a run (same indentation, nested items don't break it)
 * the first item keeps its number and the following ones count up from it.
 */
export function renumberLists(text: string): string {
  const next = new Map<number, number>()
  const dropFrom = (level: number) => {
    for (const k of [...next.keys()]) if (k >= level) next.delete(k)
  }
  return text
    .split('\n')
    .map((line) => {
      const l = parseLine(line)
      const level = indentWidth(l.indent)
      if (l.kind === 'numbered') {
        dropFrom(level + 1)
        const n = next.get(level) ?? l.num ?? 1
        next.set(level, n + 1)
        return n === l.num ? line : formatLine({ ...l, marker: `${n}. ` })
      }
      if (l.kind === 'bullet') {
        dropFrom(level)
      } else if (l.body.trim() || l.kind === 'quote') {
        // Text (or a quote) at this indentation ends the lists at this level and deeper
        dropFrom(level)
      }
      return line
    })
    .join('\n')
}

/**
 * Toggle a line prefix (quote / bullet / numbered list) on every line touched
 * by the selection. Indentation is kept; switching list types replaces the old prefix.
 */
export function toggleLinePrefix(text: string, selStart: number, selEnd: number, format: LineFormat): TextEdit {
  const { lineStart, lineEnd } = touchedLines(text, selStart, selEnd)
  const lines = text.slice(lineStart, lineEnd).split('\n').map(parseLine)
  const targets = lines.some((l) => (l.marker + l.body).trim())
    ? lines.map((l) => !!(l.marker + l.body).trim())
    : lines.map(() => true)
  const kind: LineKind = format
  const remove = lines.every((l, i) => !targets[i] || l.kind === kind)

  let n = 0
  const replaced = lines
    .map((l, i) => {
      if (!targets[i]) return formatLine(l)
      if (remove) return l.indent + l.body
      n++
      const marker = format === 'quote' ? '> ' : format === 'bullet' ? '- ' : `${n}. `
      return l.indent + marker + l.body
    })
    .join('\n')

  const firstLine = lineIndexAt(text, lineStart)
  const after = text.slice(lineEnd)
  const renumbered = renumberLists(text.slice(0, lineStart) + replaced + after)
  const newStart = lineOffset(renumbered, firstLine)
  const blockEnd = renumbered.length - after.length
  if (selStart === selEnd && lines.length === 1) {
    return { text: renumbered, selStart: blockEnd, selEnd: blockEnd }
  }
  return { text: renumbered, selStart: newStart, selEnd: blockEnd }
}

/**
 * Indent (+1) or outdent (-1) every line touched by the selection by one INDENT_UNIT.
 * Numbered items that change level are renumbered within their new list.
 */
export function shiftIndent(text: string, selStart: number, selEnd: number, direction: 1 | -1): TextEdit {
  const { lineStart, lineEnd } = touchedLines(text, selStart, selEnd)
  const lines = text.slice(lineStart, lineEnd).split('\n')
  const deltas: number[] = []
  const shifted = lines.map((line) => {
    if (direction === 1) {
      if (!line.trim() && lines.length > 1) {
        deltas.push(0)
        return line
      }
      deltas.push(INDENT_UNIT.length)
      const l = parseLine(line)
      // Moved numbered items start fresh; renumbering below fits them into their run
      return INDENT_UNIT + (l.kind === 'numbered' ? formatLine({ ...l, marker: '1. ' }) : line)
    }
    const m = line.match(/^(\t| {1,4})/)
    const cut = m?.[1]?.length ?? 0
    deltas.push(-cut)
    if (!cut) return line
    const l = parseLine(line.slice(cut))
    return l.kind === 'numbered' ? formatLine({ ...l, marker: '1. ' }) : formatLine(l)
  })

  const firstLine = lineIndexAt(text, lineStart)
  const result = renumberLists(text.slice(0, lineStart) + shifted.join('\n') + text.slice(lineEnd))
  // Renumbering may change line lengths before the block, so locate it by line index
  const newStart = lineOffset(result, firstLine)
  const blockLen = result.split('\n').slice(firstLine, firstLine + lines.length).join('\n').length

  if (selStart === selEnd && lines.length === 1) {
    // Keep the caret on the same character of the line (never left of the line start)
    const offset = Math.max(0, selStart - lineStart + (deltas[0] ?? 0))
    const caret = newStart + Math.min(offset, blockLen)
    return { text: result, selStart: caret, selEnd: caret }
  }
  return { text: result, selStart: newStart, selEnd: newStart + blockLen }
}

/**
 * Insert a line break that continues the current list / quote / indentation:
 * "2. foo|" -> "2. foo\n3. |". On an empty item the list ends instead
 * (nested items move one level out first).
 */
export function insertNewline(text: string, selStart: number, selEnd: number): TextEdit {
  const start = Math.min(selStart, selEnd)
  const end = Math.max(selStart, selEnd)
  const lineStart = text.lastIndexOf('\n', start - 1) + 1
  const nl = text.indexOf('\n', end)
  const lineEnd = nl === -1 ? text.length : nl
  const l = parseLine(text.slice(lineStart, lineEnd))
  const prefixLen = l.indent.length + l.marker.length

  // Empty list item / quote line with the caret at its end -> leave the list
  if (start === end && l.kind !== 'plain' && !l.body.trim() && start === lineEnd) {
    if (l.indent) return shiftIndent(text, start, end, -1)
    const cleared = text.slice(0, lineStart) + text.slice(lineEnd)
    return { text: cleared, selStart: lineStart, selEnd: lineStart }
  }

  // Caret inside the prefix itself -> plain line break
  let continuation = ''
  if (start - lineStart >= prefixLen) {
    if (l.kind === 'numbered') continuation = `${l.indent}${(l.num ?? 0) + 1}. `
    else if (l.kind === 'bullet') continuation = l.indent + l.marker.trimEnd() + ' '
    else continuation = l.indent + l.marker
  }

  const insert = '\n' + continuation
  const raw = text.slice(0, start) + insert + text.slice(end)
  if (l.kind !== 'numbered') {
    const caret = start + insert.length
    return { text: raw, selStart: caret, selEnd: caret }
  }
  // Renumbering may change earlier lines too: put the caret after the new line's prefix
  const result = renumberLists(raw)
  const newLine = lineIndexAt(raw, start) + 1
  const newLineStart = lineOffset(result, newLine)
  const nl2 = result.indexOf('\n', newLineStart)
  const p = parseLine(result.slice(newLineStart, nl2 === -1 ? result.length : nl2))
  const caret = newLineStart + (continuation ? p.indent.length + p.marker.length : 0)
  return { text: result, selStart: caret, selEnd: caret }
}
