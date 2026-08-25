import { Box, Button, Typography } from '@mui/material'
import { Navigate, Route, Routes, useNavigate } from 'react-router-dom'
import { LoadingState } from '@/components/states'
import { AppLayout } from '@/layouts/AppLayout'
import { useAuth } from '@/features/auth/AuthContext'
import { LoginPage } from '@/features/auth/LoginPage'
import { DashboardPage } from '@/features/dashboard/DashboardPage'
import { ShopsPage } from '@/features/shops/ShopsPage'
import { ItemsPage } from '@/features/items/ItemsPage'
import { DevicesPage } from '@/features/devices/DevicesPage'
import { StockImportPage } from '@/features/stock/StockImportPage'
import { ItemStockPage } from '@/features/stock/ItemStockPage'
import { HhtSubmissionsPage } from '@/features/hht/HhtSubmissionsPage'
import { HhtSimulatorPage } from '@/features/hht/HhtSimulatorPage'
import { AuditsPage } from '@/features/audits/AuditsPage'
import { AuditDetailPage } from '@/features/audits/AuditDetailPage'
import { VerificationPage } from '@/features/verification/VerificationPage'
import { VariancePage } from '@/features/variance/VariancePage'
import { AdjustmentsPage } from '@/features/adjustments/AdjustmentsPage'
import { StockTakePage } from '@/features/stock-take/StockTakePage'
import { ReportsPage } from '@/features/reports/ReportsPage'
import { FinalOutputPage } from '@/features/final-output/FinalOutputPage'
import { UsersPage } from '@/features/users/UsersPage'
import { SettingsPage } from '@/features/settings/SettingsPage'
import { ActivityLogPage } from '@/features/activity/ActivityLogPage'

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
        <Route index element={<DashboardPage />} />

        <Route path="shops" element={<RequirePermission permission="shops.view"><ShopsPage /></RequirePermission>} />
        <Route path="items" element={<RequirePermission permission="items.view"><ItemsPage /></RequirePermission>} />
        <Route path="devices" element={<RequirePermission permission="devices.view"><DevicesPage /></RequirePermission>} />

        <Route path="stock-import" element={<RequirePermission permission="stock.view"><StockImportPage /></RequirePermission>} />
        <Route path="item-stock" element={<RequirePermission permission="stock.view"><ItemStockPage /></RequirePermission>} />

        <Route path="hht" element={<RequirePermission permission="hht.view"><HhtSubmissionsPage /></RequirePermission>} />
        <Route path="hht/simulator" element={<RequirePermission permission="hht.view"><HhtSimulatorPage /></RequirePermission>} />

        <Route path="audits" element={<RequirePermission permission="audits.view"><AuditsPage /></RequirePermission>} />
        <Route path="audits/:auditId" element={<RequirePermission permission="audits.view"><AuditDetailPage /></RequirePermission>} />

        <Route path="verification" element={<RequirePermission permission="audits.view"><VerificationPage /></RequirePermission>} />
        <Route path="variance" element={<RequirePermission permission="variance.view"><VariancePage /></RequirePermission>} />
        <Route path="adjustments" element={<RequirePermission permission="adjustments.view"><AdjustmentsPage /></RequirePermission>} />
        <Route path="stock-take" element={<RequirePermission permission="stocktake.view"><StockTakePage /></RequirePermission>} />

        <Route path="reports" element={<RequirePermission permission="reports.view"><ReportsPage /></RequirePermission>} />
        <Route path="reports/:reportKey" element={<RequirePermission permission="reports.view"><ReportsPage /></RequirePermission>} />

        <Route path="final-output" element={<RequirePermission permission="finaloutput.view"><FinalOutputPage /></RequirePermission>} />

        <Route path="users" element={<RequirePermission permission="users.manage"><UsersPage /></RequirePermission>} />
        <Route path="activity-log" element={<RequirePermission permission="activity.view"><ActivityLogPage /></RequirePermission>} />
        <Route path="settings" element={<SettingsPage />} />
      </Route>

      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
