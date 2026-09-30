/**
 * Build a CSV from rows and hand it to the browser as a download.
 *
 * Every cell is quoted and inner quotes are doubled, so a diagnosis or a
 * medicine note containing a comma survives the round trip into Excel.
 */
export function downloadCsv(filename: string, rows: Array<Array<string | number | null>>) {
  const csv = rows
    .map((row) => row.map((cell) => `"${String(cell ?? '').replace(/"/g, '""')}"`).join(','))
    .join('\n')
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()
  URL.revokeObjectURL(url)
}
