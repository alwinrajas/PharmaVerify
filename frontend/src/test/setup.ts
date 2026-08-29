import '@testing-library/jest-dom/vitest'
import { cleanup, configure } from '@testing-library/react'
import { afterAll, afterEach, beforeAll } from 'vitest'
import { server } from './server'

// Testing Library gives every findBy* one second. A screen that mounts a lazy
// route, resolves auth and then waits on a mocked request can exceed that under
// full-suite load on a busy machine — which shows up as a test that fails once
// and passes on its own, the least useful kind of failure. The work is the same
// either way; this only stops the clock being the thing that decides.
configure({ asyncUtilTimeout: 5000 })

// The API is mocked at the network boundary, so the components under test use
// the real axios client, the real interceptors and the real error translation.
beforeAll(() => server.listen({ onUnhandledRequest: 'error' }))

afterEach(() => {
  server.resetHandlers()
  cleanup()
  localStorage.clear()
})

afterAll(() => server.close())
