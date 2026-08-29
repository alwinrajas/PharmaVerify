import { setupServer } from 'msw/node'
import { http, HttpResponse } from 'msw'

/**
 * The mock API.
 *
 * Handlers are declared per test with `server.use(...)`, so each test states
 * exactly what the API does for it. Anything a test did not declare fails
 * loudly rather than silently returning nothing — see `onUnhandledRequest` in
 * the setup file.
 *
 * The public stats endpoint is called on mount by the login page, which is
 * briefly rendered whenever navigating to protected routes while unauthenticated.
 */
export const server = setupServer(
  http.get('*/api/public/stats', () =>
    HttpResponse.json({
      success: true,
      data: { total_shops: 5, total_items: 1250, stock_records: 4800, completed_audits: 142 },
    }),
  ),
)
