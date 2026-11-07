import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
// Brand font, bundled with the app (no CDN) so the offline cache keeps working.
import '@fontsource-variable/atkinson-hyperlegible-next'
import './index.css'
import App from './App.tsx'
import { ToastProvider } from './components/Toast.tsx'
import { AuthProvider } from './context/AuthContext.tsx'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <BrowserRouter>
      <AuthProvider>
        <ToastProvider>
          <App />
        </ToastProvider>
      </AuthProvider>
    </BrowserRouter>
  </StrictMode>,
)
