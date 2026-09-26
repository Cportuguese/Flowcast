// Chart colors (recharts needs literal strings, so these live here rather
// than in index.css). One hue for the real series, one for forecasts.
//
// `band` is not a third series — it is the p10–p90 prediction interval that
// belongs to the forecast line, so it is a tint of the same hue rather than
// a new one. Colouring it separately would read as another measurement
// instead of the uncertainty around that one.
export const CHART_COLORS = {
  actual: '#2a78d6',
  forecast: '#eb6834',
  band: '#f9d2c2',
  muted: '#898781',
  grid: '#e1e0d9',
} as const
