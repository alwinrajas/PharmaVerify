import { screen } from '@testing-library/react'
import { HttpResponse, http } from 'msw'
import { describe, expect, it } from 'vitest'
import { AppRoutes } from './AppRoutes'
import { AuthProvider } from '@/features/auth/AuthContext'
import { tokenStore } from '@/services/apiClient'
import { server } from '@/test/server'
import { makeAuthUser, renderWithProviders } from '@/test/utils'

/**
 * Route guards.
 *
 * These decide what a signed-in person can reach. They are a convenience only —
 * the API refuses the request regardless — but a guard that let the wrong
 * screen through would still be a real defect.
 */
function signedInAs(permissions: string[], roles = ['Administrator']) {
  tokenStore.set('test-token')
  server.use(
    http.get('/api/auth/me', () =>
      HttpResponse.json({ success: true, data: makeAuthUser({ permissions, roles }) }),
    ),
    // Whatever screen renders, its own queries are answered emptily so the test
    // is about the guard and not about the screen's data.
    http.get('/api/*', () => HttpResponse.json({ success: true, data: [], meta: { total: 0 } })),
  )
}

function renderAt(route: string) {
  return renderWithProviders(
    <AuthProvider>
      <AppRoutes />
    </AuthProvider>,
    { route },
  )
}

describe('route guards', () => {
  it('sends a signed-out visitor to the sign-in screen', async () => {
    renderAt('/shops')

    expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument()
  })

  it('lets a permitted user reach a protected screen', async () => {
    signedInAs(['shops.view', 'shops.view_all'])

    renderAt('/shops')

    expect(await screen.findByRole('heading', { name: 'Shops' })).toBeInTheDocument()
  })

  it('refuses a screen the user lacks the permission for', async () => {
    signedInAs(['shops.view'])

    renderAt('/users')

    expect(await screen.findByRole('heading', { name: /do not have access/i })).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'User Management' })).not.toBeInTheDocument()
  })

  it('explains the refusal rather than showing an empty screen', async () => {
    signedInAs(['shops.view'])

    renderAt('/activity-log')

    expect(await screen.findByText(/ask your administrator/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /back to dashboard/i })).toBeInTheDocument()
  })

  it('hides navigation the user cannot use', async () => {
    signedInAs(['shops.view', 'items.view'])

    renderAt('/shops')
    await screen.findByRole('heading', { name: 'Shops' })

    const nav = screen.getAllByRole('navigation')[0]

    expect(nav).toBeTruthy()
    expect(screen.queryByRole('link', { name: /^Users$/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /Activity Log/ })).not.toBeInTheDocument()
  })

  it('shows navigation the user can use', async () => {
    signedInAs(['shops.view', 'items.view', 'users.manage', 'activity.view'])

    renderAt('/shops')
    await screen.findByRole('heading', { name: 'Shops' })

    expect(screen.getByRole('link', { name: /^Users$/ })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Activity Log/ })).toBeInTheDocument()
  })

  it('keeps Settings reachable for any signed-in user', async () => {
    signedInAs(['shops.view'])

    // Settings reads a shape of its own, so the generic empty list will not do.
    server.use(
      http.get('/api/settings', () =>
        HttpResponse.json({
          success: true,
          data: {
            groups: {},
            integrations: { onedrive_driver: 'demo', onedrive_folder: 'PharmaVerify', onedrive_configured: false },
          },
        }),
      ),
    )

    renderAt('/settings')

    // Settings is deliberately not permission-guarded; saving is.
    expect(await screen.findByRole('heading', { name: 'Settings' })).toBeInTheDocument()
  })
})
