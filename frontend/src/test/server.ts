import { setupServer } from 'msw/node'

/**
 * The mock API.
 *
 * Handlers are declared per test with `server.use(...)`, so each test states
 * exactly what the API does for it. Anything a test did not declare fails
 * loudly rather than silently returning nothing — see `onUnhandledRequest` in
 * the setup file.
 */
export const server = setupServer()
