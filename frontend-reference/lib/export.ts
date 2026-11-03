// Descargas del lado del cliente: CSV como archivo y PDF mediante el diálogo de impresión del navegador.

type Cell = string | number

const csvCell = (value: Cell) => {
  const text = String(value)
  return /[",\n;]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text
}

export function downloadCSV(filename: string, rows: Cell[][]) {
  // BOM para que Excel respete los acentos.
  const blob = new Blob(['﻿' + rows.map((r) => r.map(csvCell).join(',')).join('\r\n')], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

const escapeHtml = (text: Cell) => String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')

export type PdfReport = {
  title: string
  subtitle?: string
  summary?: [string, string][]
  head: string[]
  body: Cell[][]
  foot?: Cell[]
  note?: string
}

export function printPDF(report: PdfReport) {
  const { title, subtitle, summary = [], head, body, foot, note } = report
  const html = `<!doctype html><html lang="es"><head><meta charset="utf-8"><title>${escapeHtml(title)}</title>
<style>
  body { font-family: system-ui, sans-serif; color: #17222d; margin: 32px; font-size: 12px; }
  h1 { font-size: 20px; margin: 0; } p.sub { color: #5b6b78; margin: 4px 0 16px; }
  dl { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px; margin: 0 0 16px; }
  dl div { border: 1px solid #dde3e8; border-radius: 6px; padding: 8px 10px; } dt { color: #5b6b78; font-size: 10px; text-transform: uppercase; } dd { margin: 2px 0 0; font-weight: 700; font-size: 14px; }
  table { border-collapse: collapse; width: 100%; } th, td { border-bottom: 1px solid #dde3e8; padding: 6px 8px; text-align: left; }
  th { background: #f4f1e8; font-size: 10px; text-transform: uppercase; } td.n, th.n { text-align: right; } tfoot td { font-weight: 700; border-top: 2px solid #17222d; }
  p.note { margin-top: 16px; color: #5b6b78; }
</style></head><body>
<h1>${escapeHtml(title)}</h1>${subtitle ? `<p class="sub">${escapeHtml(subtitle)}</p>` : ''}
${summary.length ? `<dl>${summary.map(([k, v]) => `<div><dt>${escapeHtml(k)}</dt><dd>${escapeHtml(v)}</dd></div>`).join('')}</dl>` : ''}
<table><thead><tr>${head.map((h, i) => `<th${i > 0 ? ' class="n"' : ''}>${escapeHtml(h)}</th>`).join('')}</tr></thead>
<tbody>${body.map((r) => `<tr>${r.map((c, i) => `<td${i > 0 ? ' class="n"' : ''}>${escapeHtml(c)}</td>`).join('')}</tr>`).join('')}</tbody>
${foot ? `<tfoot><tr>${foot.map((c, i) => `<td${i > 0 ? ' class="n"' : ''}>${escapeHtml(c)}</td>`).join('')}</tr></tfoot>` : ''}</table>
${note ? `<p class="note">${escapeHtml(note)}</p>` : ''}
</body></html>`

  // Iframe oculto en lugar de window.open para que no lo bloquee el navegador.
  const frame = document.createElement('iframe')
  frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0'
  frame.srcdoc = html
  frame.onload = () => {
    frame.contentWindow?.focus()
    frame.contentWindow?.print()
    window.setTimeout(() => frame.remove(), 60_000)
  }
  document.body.appendChild(frame)
}
