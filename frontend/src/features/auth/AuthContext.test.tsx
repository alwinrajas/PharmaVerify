import { screen, waitFor } from '@testing-library/react'
import { HttpResponse, http } from 'msw'
import { describe, expect, it } from 'vitest'
import { AuthProvider, useAuth } from './AuthContext'
import { tokenStore } from '@/services/apiClient'
import { server } from '@/test/server'
import { makeAuthUser, renderWithProviders } from '@/test/utils'

/** A probe that renders what the context is reporting. */
function AuthProbe() {
  const { user, loading, can, hasRole, signIn, signOut } = useAuth()

  if (loading) return <p>restoring…</p>

  return (
    <div>
      <p data-testid="who">{user ? user.name : 'signed out'}</p>
      <p data-testid="can-adjust">{can('adjustments.create') ? 'yes' : 'no'}</p>
      <p data-testid="can-manage-users">{can('users.manage') ? 'yes' : 'no'}</p>
      <p data-testid="can-either">{can(['users.manage', 'adjustments.create']) ? 'yes' : 'no'}</p>
      <p data-testid="is-admin">{hasRole('Administrator') ? 'yes' : 'no'}</p>
      <button type="button" onClick={() => void signIn('admin@pharmaverify.com', 'Pharma@2026')}>
        Sign in
      </button>
      <button type="button" onClick={() => void signOut()}>
        Sign out
      </button>
    </div>
  )
}

function renderAuth() {
  return renderWithProviders(
    <AuthProvider>
      <AuthProbe />
    </AuthProvider>,
  )
}

describe('AuthContext', () => {
  it('reports a signed-out user when no token is stored', async () => {
    renderAuth()

    expect(await screen.findByTestId('who')).toHaveTextContent('signed out')
  })

  it('restores the session from a stored token so a refresh does not sign the user out', async () => {
    tokenStore.set('stored-token')
    server.use(http.get('/api/auth/me', () => HttpResponse.json({ success: true, data: makeAuthUser() })))

    renderAuth()

    expect(await screen.findByTestId('who')).toHaveTextContent('Arun Prakash')
  })

  it('discards a token the server no longer accepts', async () => {
    tokenStore.set('expired-token')
    server.use(http.get('/api/auth/me', () => HttpResponse.json({ success: false }, { status: 401 })))

    renderAuth()

    expect(await screen.findByTestId('who')).toHaveTextContent('signed out')
    await waitFor(() => expect(tokenStore.get()).toBeNull())
  })

  it('stores the token and the user on a successful sign-in', async () => {
    server.use(
      http.post('/api/auth/login', () =>
        HttpResponse.json({ success: true, data: { token: 'fresh-token', user: makeAuthUser() } }),
      ),
    )

    const { user } = renderAuth()
    await screen.findByTestId('who')

    await user.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByTestId('who')).toHaveTextContent('Arun Prakash')
    expect(tokenStore.get()).toBe('fresh-token')
  })

  it('clears the session on sign-out even when the server call fails', async () => {
    tokenStore.set('stored-token')
    server.use(
      http.get('/api/auth/me', () => HttpResponse.json({ success: true, data: makeAuthUser() })),
      // Signing out locally matters more than the server acknowledging it.
      http.post('/api/auth/logout', () => HttpResponse.error()),
    )

    const { user } = renderAuth()
    expect(await screen.findByTestId('who')).toHaveTextContent('Arun Prakash')

    await user.click(screen.getByRole('button', { name: 'Sign out' }))

    expect(await screen.findByTestId('who')).toHaveTextContent('signed out')
    expect(tokenStore.get()).toBeNull()
  })

  it('answers permission questions from the signed-in user', async () => {
    tokenStore.set('stored-token')
    server.use(
      http.get('/api/auth/me', () =>
        HttpResponse.json({
          success: true,
          data: makeAuthUser({ roles: ['Shop User'], permissions: ['shops.view', 'stocktake.create'] }),
        }),
      ),
    )

    renderAuth()
    await screen.findByTestId('who')

    expect(screen.getByTestId('can-adjust')).toHaveTextContent('no')
    expect(screen.getByTestId('can-manage-users')).toHaveTextContent('no')
    expect(screen.getByTestId('is-admin')).toHaveTextContent('no')
  })

  it('treats a list of permissions as "any of these"', async () => {
    tokenStore.set('stored-token')
    server.use(
      http.get('/api/auth/me', () =>
        HttpResponse.json({
          success: true,
          data: makeAuthUser({ permissions: ['adjustments.create'] }),
        }),
      ),
    )

    renderAuth()
    await screen.findByTestId('who')

    expect(screen.getByTestId('can-manage-users')).toHaveTextContent('no')
    expect(screen.getByTestId('can-either')).toHaveTextContent('yes')
  })

  it('grants nothing at all while signed out', async () => {
    renderAuth()
    await screen.findByTestId('who')

    expect(screen.getByTestId('can-adjust')).toHaveTextContent('no')
    expect(screen.getByTestId('can-either')).toHaveTextContent('no')
    expect(screen.getByTestId('is-admin')).toHaveTextContent('no')
  })
})
