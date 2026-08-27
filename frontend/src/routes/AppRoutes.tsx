import { Box, Button, Typography } from '@mui/material'
import { Component, Suspense, lazy, type ReactElement, type ReactNode } from 'react'
import { Navigate, Route, Routes, useNavigate } from 'react-router-dom'
import { ErrorState, LoadingState } from '@/components/states'
import { AppLayout } from '@/layouts/AppLayout'
import { useAuth } from '@/features/auth/AuthContext'
import { LoginPage } from '@/features/auth/LoginPage'

/*
 * Every screen below the sign-in page is loaded on demand, so opening
 * PharmaVerify no longer downloads the reports engine, the HHT simulator and
 * the administration screens before the sign-in form can be shown.
 *
 * LoginPage is deliberately not split: it is the first thing a signed-out
 * visitor sees, and fetching a second file before it could appear would trade
 * one delay for another.
 *
 * The import specifiers are written out in full rather than built from a
 * variable, because the bundler can only split what it can read statically.
 */
const DashboardPage = lazy(async () => ({ default: (await import('@/features/dashboard/DashboardPage')).DashboardPage }))
const ShopsPage = lazy(async () => ({ default: (await import('@/features/shops/ShopsPage')).ShopsPage }))
const ItemsPage = lazy(async () => ({ default: (await import('@/features/items/ItemsPage')).ItemsPage }))
const DevicesPage = lazy(async () => ({ default: (await import('@/features/devices/DevicesPage')).DevicesPage }))
const StockImportPage = lazy(async () => ({ default: (await import('@/features/stock/StockImportPage')).StockImportPage }))
const ItemStockPage = lazy(async () => ({ default: (await import('@/features/stock/ItemStockPage')).ItemStockPage }))
const HhtSubmissionsPage = lazy(async () => ({ default: (await import('@/features/hht/HhtSubmissionsPage')).HhtSubmissionsPage }))
const HhtSimulatorPage = lazy(async () => ({ default: (await import('@/features/hht/HhtSimulatorPage')).HhtSimulatorPage }))
const AuditsPage = lazy(async () => ({ default: (await import('@/features/audits/AuditsPage')).AuditsPage }))
const AuditDetailPage = lazy(async () => ({ default: (await import('@/features/audits/AuditDetailPage')).AuditDetailPage }))
const VerificationPage = lazy(async () => ({ default: (await import('@/features/verification/VerificationPage')).VerificationPage }))
const VariancePage = lazy(async () => ({ default: (await import('@/features/variance/VariancePage')).VariancePage }))
const AdjustmentsPage = lazy(async () => ({ default: (await import('@/features/adjustments/AdjustmentsPage')).AdjustmentsPage }))
const StockTakePage = lazy(async () => ({ default: (await import('@/features/stock-take/StockTakePage')).StockTakePage }))
const ReportsPage = lazy(async () => ({ default: (await import('@/features/reports/ReportsPage')).ReportsPage }))
const FinalOutputPage = lazy(async () => ({ default: (await import('@/features/final-output/FinalOutputPage')).FinalOutputPage }))
const UsersPage = lazy(async () => ({ default: (await import('@/features/users/UsersPage')).UsersPage }))
const SettingsPage = lazy(async () => ({ default: (await import('@/features/settings/SettingsPage')).SettingsPage }))
const ActivityLogPage = lazy(async () => ({ default: (await import('@/features/activity/ActivityLogPage')).ActivityLogPage }))

/** Only signed-in users get past here. */
function RequireAuth({ children }: { children: React.ReactElement }) {
  const { user, loading } = useAuth()

  if (loading) {
    return <LoadingState label="Restoring your session…" height="100vh" />
  }

  return user ? children : <Navigate to="/login" replace />
}

/** Guards a route behind a permission, in step with the sidebar. */
function RequirePermission({ permission, children }: { permission: string; children: React.ReactElement }) {
  const { can } = useAuth()
  const navigate = useNavigate()

  if (can(permission)) return children

  return (
    <Box sx={{ py: 8, textAlign: 'center' }}>
      <Typography variant="h3" sx={{ mb: 1 }}>
        You do not have access to this screen
      </Typography>
      <Typography variant="body2" color="text.secondary" sx={{ mb: 3 }}>
        Ask your administrator if you believe you should be able to see it.
      </Typography>
      <Button variant="outlined" onClick={() => navigate('/')}>
        Back to dashboard
      </Button>
    </Box>
  )
}

/**
 * Catches a screen that could not be fetched.
 *
 * A dynamic import fails for prosaic reasons — the connection dropped, or the
 * application was redeployed while this tab was open and the file it is asking
 * for no longer exists. Without this the whole page would go blank, so the
 * failure is shown in the same words as any other, with a reload rather than a
 * retry: after a deployment the tab needs the new index, not another attempt at
 * the old file.
 */
class ScreenBoundary extends Component<{ children: ReactNode }, { failed: boolean }> {
  state = { failed: false }

  static getDerivedStateFromError() {
    return { failed: true }
  }

  render() {
    if (this.state.failed) {
      return (
        <ErrorState
          message="This screen could not be loaded. Your connection may have dropped, or the application may have been updated since you opened this tab."
          onRetry={() => window.location.reload()}
        />
      )
    }

    return this.props.children
  }
}

/**
 * One screen: its permission, its loading state and its failure state.
 *
 * The permission check sits outside the boundary on purpose. It renders
 * immediately and, when it refuses, returns the refusal without ever touching
 * the lazy component — so a user who may not see a screen does not download it
 * either.
 */
function Screen({ permission, children }: { permission?: string; children: ReactElement }) {
  const guarded = permission ? <RequirePermission permission={permission}>{children}</RequirePermission> : children

  return (
    <ScreenBoundary>
      <Suspense fallback={<LoadingState label="Loading this screen…" height="60vh" />}>{guarded}</Suspense>
    </ScreenBoundary>
  )
}

export function AppRoutes() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />

      <Route
        element={
          <RequireAuth>
            <AppLayout />
          </RequireAuth>
        }
      >
        <Route index element={<Screen><DashboardPage /></Screen>} />

        <Route path="shops" element={<Screen permission="shops.view"><ShopsPage /></Screen>} />
        <Route path="items" element={<Screen permission="items.view"><ItemsPage /></Screen>} />
        <Route path="devices" element={<Screen permission="devices.view"><DevicesPage /></Screen>} />

        <Route path="stock-import" element={<Screen permission="stock.view"><StockImportPage /></Screen>} />
        <Route path="item-stock" element={<Screen permission="stock.view"><ItemStockPage /></Screen>} />

        <Route path="hht" element={<Screen permission="hht.view"><HhtSubmissionsPage /></Screen>} />
        <Route path="hht/simulator" element={<Screen permission="hht.view"><HhtSimulatorPage /></Screen>} />

        <Route path="audits" element={<Screen permission="audits.view"><AuditsPage /></Screen>} />
        <Route path="audits/:auditId" element={<Screen permission="audits.view"><AuditDetailPage /></Screen>} />

        <Route path="verification" element={<Screen permission="audits.view"><VerificationPage /></Screen>} />
        <Route path="variance" element={<Screen permission="variance.view"><VariancePage /></Screen>} />
        <Route path="adjustments" element={<Screen permission="adjustments.view"><AdjustmentsPage /></Screen>} />
        <Route path="stock-take" element={<Screen permission="stocktake.view"><StockTakePage /></Screen>} />

        <Route path="reports" element={<Screen permission="reports.view"><ReportsPage /></Screen>} />
        <Route path="reports/:reportKey" element={<Screen permission="reports.view"><ReportsPage /></Screen>} />

        <Route path="final-output" element={<Screen permission="finaloutput.view"><FinalOutputPage /></Screen>} />

        <Route path="users" element={<Screen permission="users.manage"><UsersPage /></Screen>} />
        <Route path="activity-log" element={<Screen permission="activity.view"><ActivityLogPage /></Screen>} />
        <Route path="settings" element={<Screen><SettingsPage /></Screen>} />
      </Route>

      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
