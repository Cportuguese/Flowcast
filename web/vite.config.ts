import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig, loadEnv, type Plugin } from 'vite'

/**
 * Content-Security-Policy for the production build, delivered as a <meta>
 * tag so it works from any static host without server config. Build-only:
 * the dev server injects an inline React Refresh script that a strict
 * script-src would block.
 *
 * The bearer token lives in localStorage, so XSS is the one attack that
 * would hand over a session — this is the mitigation for that (see
 * DEPLOYMENT.md). connect-src must list the API and Reverb origins.
 */
function contentSecurityPolicy(env: Record<string, string>): Plugin {
  const apiOrigin = originOf(env.VITE_API_URL ?? 'http://127.0.0.1:8000/api')
  const wsScheme = env.VITE_REVERB_SCHEME === 'https' ? 'wss' : 'ws'
  const wsOrigin = `${wsScheme}://${env.VITE_REVERB_HOST ?? 'localhost'}:${env.VITE_REVERB_PORT ?? '8080'}`
  const extra = (env.VITE_CSP_CONNECT_SRC ?? '').split(',').map((s) => s.trim()).filter(Boolean)

  const policy = [
    "default-src 'self'",
    "script-src 'self'",
    // Tailwind ships compiled CSS; 'unsafe-inline' is only for React style props.
    "style-src 'self' 'unsafe-inline'",
    "img-src 'self' data: blob:",
    "font-src 'self' data:",
    `connect-src 'self' ${[apiOrigin, wsOrigin, ...extra].join(' ')}`,
    // Certificate PDFs are handed to the browser as blob: downloads.
    "object-src 'none'",
    "base-uri 'self'",
    "form-action 'self'",
  ].join('; ')

  return {
    name: 'flowcast-csp',
    apply: 'build',
    transformIndexHtml(html) {
      return html.replace(
        '<meta charset="UTF-8" />',
        `<meta charset="UTF-8" />\n    <meta http-equiv="Content-Security-Policy" content="${policy}" />`,
      )
    },
  }
}

function originOf(url: string): string {
  try {
    return new URL(url).origin
  } catch {
    return url
  }
}

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), 'VITE_')

  return {
    plugins: [react(), tailwindcss(), contentSecurityPolicy(env)],
  }
})
