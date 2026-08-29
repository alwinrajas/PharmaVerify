import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
// Inter, bundled rather than fetched from Google's CDN. The application is
// deployed to an on-premises PC that may have no internet access, where a CDN
// font silently falls back to Segoe UI and every screen loses the metrics the
// design was built on.
import '@fontsource/inter/400.css'
import '@fontsource/inter/500.css'
import '@fontsource/inter/600.css'
import '@fontsource/inter/700.css'
import './index.css'
import App from './App.tsx'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
