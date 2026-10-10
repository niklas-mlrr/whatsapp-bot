import { describe, it, expect } from 'vitest'
import {
  renderWhatsAppText,
  hasWhatsAppFormatting,
  toggleInlineFormat,
  toggleLinePrefix,
  insertNewline,
  shiftIndent,
  renumberLists,
} from '../whatsappFormat'

describe('renderWhatsAppText', () => {
  it('escapes HTML so message text cannot inject markup', () => {
    const html = renderWhatsAppText('<img src=x onerror="alert(1)"> & <script>x</script>')
    expect(html).not.toContain('<img')
    expect(html).not.toContain('<script')
    expect(html).toContain('&lt;img src=x onerror=&quot;alert(1)&quot;&gt; &amp; &lt;script&gt;')
  })

  it('renders bold, italic, strike', () => {
    expect(renderWhatsAppText('*fett* _kursiv_ ~durch~')).toBe('<strong>fett</strong> <em>kursiv</em> <del>durch</del>')
  })

  it('supports nested styles', () => {
    expect(renderWhatsAppText('*_beides_*')).toBe('<strong><em>beides</em></strong>')
  })

  it('leaves markers inside words and with spaces alone', () => {
    expect(renderWhatsAppText('snake_case_name')).toBe('snake_case_name')
    expect(renderWhatsAppText('2*3*4')).toBe('2*3*4')
    expect(renderWhatsAppText('* nicht fett *')).toContain('nicht fett *')
    expect(renderWhatsAppText('a * b * c')).toBe('a * b * c')
  })

  it('formats inside punctuation', () => {
    expect(renderWhatsAppText('(*wichtig*).')).toBe('(<strong>wichtig</strong>).')
  })

  it('linkifies URLs without mangling underscores and keeps formatting around them', () => {
    const html = renderWhatsAppText('siehe https://example.com/a_b_c ok')
    expect(html).toContain('href="https://example.com/a_b_c"')
    expect(html).not.toContain('<em>')
    expect(renderWhatsAppText('*https://example.com*')).toMatch(/^<strong><a href="https:\/\/example.com"[^>]*>https:\/\/example.com<\/a><\/strong>$/)
  })

  it('escapes quotes in hrefs', () => {
    const html = renderWhatsAppText('https://evil.com/"onmouseover="x')
    expect(html).not.toContain('"onmouseover')
  })

  it('does not linkify emails', () => {
    expect(renderWhatsAppText('test@example.com')).toBe('test@example.com')
  })

  it('renders inline code without formatting inside', () => {
    expect(renderWhatsAppText('`*nicht fett*`')).toBe('<code class="wa-code">*nicht fett*</code>')
  })

  it('renders monospace blocks across lines', () => {
    const html = renderWhatsAppText('vor\n```\nzeile 1\n*x*\n```\nnach')
    expect(html).toBe('vor<span class="wa-pre">zeile 1\n*x*</span>nach')
  })

  it('renders quotes and lists', () => {
    expect(renderWhatsAppText('> zitat\n> zwei')).toBe('<span class="wa-quote">zitat<br/>zwei</span>')
    expect(renderWhatsAppText('- a\n- *b*')).toBe(
      '<span class="wa-list"><span class="wa-li"><span class="wa-li-marker" style="min-width:1ch">•</span><span>a</span></span>' +
        '<span class="wa-li"><span class="wa-li-marker" style="min-width:1ch">•</span><span><strong>b</strong></span></span></span>',
    )
    expect(renderWhatsAppText('1. eins\n2. zwei')).toContain('<span class="wa-li-marker" style="min-width:2ch">2.</span>')
  })

  it('keeps line breaks and legacy <br>', () => {
    expect(renderWhatsAppText('a\nb<br>c')).toBe('a<br/>b<br/>c')
  })

  it('handles empty input', () => {
    expect(renderWhatsAppText('')).toBe('')
    expect(renderWhatsAppText(undefined)).toBe('')
  })
})

describe('hasWhatsAppFormatting', () => {
  it('detects formatting but not plain text or links', () => {
    expect(hasWhatsAppFormatting('*a*')).toBe(true)
    expect(hasWhatsAppFormatting('- item')).toBe(true)
    expect(hasWhatsAppFormatting('hallo https://x.de')).toBe(false)
    expect(hasWhatsAppFormatting('snake_case')).toBe(false)
  })
})

describe('toggleInlineFormat', () => {
  it('wraps the selection and keeps the inner text selected', () => {
    expect(toggleInlineFormat('hallo welt', 6, 10, 'bold')).toEqual({ text: 'hallo *welt*', selStart: 7, selEnd: 11 })
  })

  it('trims whitespace so markers hug the text', () => {
    expect(toggleInlineFormat('a welt b', 1, 7, 'italic').text).toBe('a _welt_ b')
  })

  it('unwraps when toggled again (selection inside markers)', () => {
    const first = toggleInlineFormat('hallo welt', 6, 10, 'bold')
    const second = toggleInlineFormat(first.text, first.selStart, first.selEnd, 'bold')
    expect(second).toEqual({ text: 'hallo welt', selStart: 6, selEnd: 10 })
  })

  it('unwraps when the selection includes the markers', () => {
    expect(toggleInlineFormat('~x~', 0, 3, 'strike').text).toBe('x')
  })

  it('inserts an empty pair at the cursor and removes it again', () => {
    const r = toggleInlineFormat('ab', 1, 1, 'bold')
    expect(r).toEqual({ text: 'a**b', selStart: 2, selEnd: 2 })
    expect(toggleInlineFormat(r.text, 2, 2, 'bold')).toEqual({ text: 'ab', selStart: 1, selEnd: 1 })
  })

  it('steps out of a formatted run when toggled again at its end', () => {
    // "_kursiv|_" -> cursor moves behind the closing marker, text unchanged
    expect(toggleInlineFormat('a _kursiv_', 9, 9, 'italic')).toEqual({ text: 'a _kursiv_', selStart: 10, selEnd: 10 })
  })

  it('wraps each line separately for multi-line selections', () => {
    const r = toggleInlineFormat('eins\n\nzwei', 0, 10, 'bold')
    expect(r.text).toBe('*eins*\n\n*zwei*')
    expect(toggleInlineFormat(r.text, r.selStart, r.selEnd, 'bold').text).toBe('eins\n\nzwei')
  })

  it('wraps multi-line selections as one monospace block', () => {
    expect(toggleInlineFormat('a\nb', 0, 3, 'mono').text).toBe('```a\nb```')
  })
})

describe('toggleLinePrefix', () => {
  it('adds and removes a bullet on the cursor line', () => {
    const r = toggleLinePrefix('eins\nzwei', 6, 6, 'bullet')
    expect(r.text).toBe('eins\n- zwei')
    expect(toggleLinePrefix(r.text, r.selStart, r.selEnd, 'bullet').text).toBe('eins\nzwei')
  })

  it('numbers all selected non-empty lines', () => {
    expect(toggleLinePrefix('a\n\nb\nc', 0, 6, 'numbered').text).toBe('1. a\n\n2. b\n3. c')
  })

  it('switches list type instead of stacking prefixes', () => {
    expect(toggleLinePrefix('- a\n- b', 0, 7, 'numbered').text).toBe('1. a\n2. b')
  })

  it('quotes an empty line', () => {
    expect(toggleLinePrefix('', 0, 0, 'quote')).toEqual({ text: '> ', selStart: 2, selEnd: 2 })
  })
})

describe('indentation and list rendering', () => {
  it('aligns numbered markers with tabular width per level', () => {
    const html = renderWhatsAppText('9. a\n10. b')
    expect(html).toContain('<span class="wa-li-marker" style="min-width:3ch">9.</span>')
    expect(html).toContain('<span class="wa-li-marker" style="min-width:3ch">10.</span>')
  })

  it('renders nested list items with padding', () => {
    const html = renderWhatsAppText('1. a\n    - sub\n2. b')
    expect(html).toContain('<span class="wa-li" style="padding-left:1.50em"><span class="wa-li-marker" style="min-width:1ch">•</span><span>sub</span></span>')
    expect(html.match(/class="wa-list"/g)).toHaveLength(1)
  })

  it('renders indented plain text with a spacer', () => {
    expect(renderWhatsAppText('a\n    b')).toBe('a<br/><span class="wa-indent" style="width:1.50em"></span>b')
  })
})

describe('insertNewline', () => {
  it('continues numbered lists and renumbers following items', () => {
    const text = '1. a\n2. b'
    expect(insertNewline(text, 4, 4)).toEqual({ text: '1. a\n2. \n3. b', selStart: 8, selEnd: 8 })
  })

  it('continues bullets, quotes and indentation', () => {
    expect(insertNewline('- a', 3, 3)).toEqual({ text: '- a\n- ', selStart: 6, selEnd: 6 })
    expect(insertNewline('> a', 3, 3).text).toBe('> a\n> ')
    expect(insertNewline('    text', 8, 8)).toEqual({ text: '    text\n    ', selStart: 13, selEnd: 13 })
    expect(insertNewline('    - a', 7, 7).text).toBe('    - a\n    - ')
  })

  it('ends the list on an empty item', () => {
    expect(insertNewline('1. a\n2. ', 8, 8)).toEqual({ text: '1. a\n', selStart: 5, selEnd: 5 })
  })

  it('moves an empty nested item one level out first', () => {
    const r = insertNewline('1. a\n    1. ', 12, 12)
    expect(r.text).toBe('1. a\n2. ')
  })

  it('is a plain line break for plain text', () => {
    expect(insertNewline('ab', 1, 1)).toEqual({ text: 'a\nb', selStart: 2, selEnd: 2 })
  })

  it('keeps the caret right after the new prefix when earlier numbers change', () => {
    const r = insertNewline('1. a\n1. b', 9, 9)
    expect(r.text).toBe('1. a\n2. b\n3. ')
    expect(r.selStart).toBe(r.text.length)
  })
})

describe('shiftIndent', () => {
  it('indents and outdents the cursor line', () => {
    const r = shiftIndent('- a', 3, 3, 1)
    expect(r).toEqual({ text: '    - a', selStart: 7, selEnd: 7 })
    expect(shiftIndent(r.text, r.selStart, r.selEnd, -1)).toEqual({ text: '- a', selStart: 3, selEnd: 3 })
  })

  it('starts a sub-list at 1 and renumbers the parent list', () => {
    expect(shiftIndent('1. a\n2. b\n3. c', 6, 6, 1).text).toBe('1. a\n    1. b\n2. c')
  })

  it('fits an outdented item back into the parent numbering', () => {
    expect(shiftIndent('1. a\n    1. b\n2. c', 12, 12, -1).text).toBe('1. a\n2. b\n3. c')
  })

  it('indents all selected lines but skips empty ones', () => {
    expect(shiftIndent('a\n\nb', 0, 4, 1).text).toBe('    a\n\n    b')
  })

  it('does nothing when outdenting an unindented line', () => {
    expect(shiftIndent('a', 1, 1, -1)).toEqual({ text: 'a', selStart: 1, selEnd: 1 })
  })
})

describe('renumberLists', () => {
  it('keeps the start number of a run', () => {
    expect(renumberLists('3. a\n3. b')).toBe('3. a\n4. b')
  })

  it('restarts after text', () => {
    expect(renumberLists('1. a\ntext\n5. b\n5. c')).toBe('1. a\ntext\n5. b\n6. c')
  })
})
