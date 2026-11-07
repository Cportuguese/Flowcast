/**
 * Build a CSV from rows and hand it to the browser as a download.
 *
 * Every cell is quoted and inner quotes are doubled, so an intervention or a
 * medicine note containing a comma survives the round trip into Excel.
 */
export function downloadCsv(filename: string, rows: Array<Array<string | number | null>>) {
  const csv = rows
    .map((row) => row.map((cell) => `"${csvText(cell).replace(/"/g, '""')}"`).join(','))
    .join('\n')
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()
  URL.revokeObjectURL(url)
}

/**
 * Spreadsheet apps run a cell that starts with =, +, - or @ as a formula,
 * so text someone typed into the system — a patient's booking reason, say —
 * could turn into a live formula on the nurse's computer. Such text gets a
 * leading apostrophe, which Excel shows as plain text. Numbers stay numbers.
 */
function csvText(cell: string | number | null) {
  if (typeof cell === 'number') return String(cell)
  const text = cell ?? ''
  return /^[=+\-@\t\r]/.test(text) ? `'${text}` : text
}
