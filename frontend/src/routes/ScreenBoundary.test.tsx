import { screen } from '@testing-library/react'
import { HttpResponse, http } from 'msw'
import { describe, expect, it, vi } from 'vitest'
import { AppRoutes } from './AppRoutes'
import { AuthProvider } from '@/features/auth/AuthContext'
import { tokenStore } from '@/services/apiClient'
import { server } from '@/test/server'
import { makeAuthUser, renderWithProviders } from '@/test/utils'

/**
 * A screen whose chunk cannot be fetched.
 *
 * Now that the routes are split, every screen arrives over the network after
 * the application has already started. That request can fail — the connection
 * drops, or a deployment lands while this tab is open and the file it asks for
 * no longer exists. Without a boundary React unmounts the tree and the user is
 * left looking at a blank page, which is why this is worth a test of its own.
 *
 * Making the module throw stands in for the failed fetch: `React.lazy` reaches
 * the same rejection whichever way the import fails.
 */
vi.mock('@/features/shops/ShopsPage', () => {
  throw new Error('Failed to fetch dynamically imported module')
})

describe('ScreenBoundary', () => {
  it('shows a readable failure with a way out when a screen cannot be loaded', async () => {
    tokenStore.set('test-token')
    server.use(
      http.get('/api/auth/me', () =>
        HttpResponse.json({ success: true, data: makeAuthUser({ permissions: ['shops.view'] }) }),
      ),
    )

    renderWithProviders(
      <AuthProvider>
        <AppRoutes />
      </AuthProvider>,
      { route: '/shops' },
    )

    expect(await screen.findByText(/could not be loaded/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /try again/i })).toBeInTheDocument()
  })
})
