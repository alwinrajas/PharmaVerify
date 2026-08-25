import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { get, post, tokenStore } from '@/services/apiClient'
import type { AuthUser } from '@/types'

interface AuthContextValue {
  user: AuthUser | null
  loading: boolean
  signIn: (email: string, password: string) => Promise<void>
  signOut: () => Promise<void>
  can: (permission: string | string[]) => boolean
  hasRole: (role: string) => boolean
}

const AuthContext = createContext<AuthContextValue | undefined>(undefined)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null)
  const [loading, setLoading] = useState(true)

  // Restore the session on a page reload so a refresh does not sign the user out.
  useEffect(() => {
    let cancelled = false

    async function restore() {
      if (!tokenStore.get()) {
        setLoading(false)
        return
      }

      try {
        const response = await get<AuthUser>('/auth/me')
        if (!cancelled) setUser(response.data)
      } catch {
        tokenStore.clear()
      } finally {
        if (!cancelled) setLoading(false)
      }
    }

    void restore()

    return () => {
      cancelled = true
    }
  }, [])

  const signIn = useCallback(async (email: string, password: string) => {
    const response = await post<{ token: string; user: AuthUser }>('/auth/login', {
      email,
      password,
      device_name: 'pharmaverify-web',
    })

    tokenStore.set(response.data.token)
    setUser(response.data.user)
  }, [])

  const signOut = useCallback(async () => {
    try {
      await post('/auth/logout')
    } catch {
      // Signing out locally matters more than the server acknowledging it.
    } finally {
      tokenStore.clear()
      setUser(null)
    }
  }, [])

  const can = useCallback(
    (permission: string | string[]) => {
      if (!user) return false

      const required = Array.isArray(permission) ? permission : [permission]

      return required.some((name) => user.permissions.includes(name))
    },
    [user],
  )

  const hasRole = useCallback((role: string) => user?.roles.includes(role) ?? false, [user])

  const value = useMemo<AuthContextValue>(
    () => ({ user, loading, signIn, signOut, can, hasRole }),
    [user, loading, signIn, signOut, can, hasRole],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext)

  if (!context) {
    throw new Error('useAuth must be used inside an AuthProvider')
  }

  return context
}
