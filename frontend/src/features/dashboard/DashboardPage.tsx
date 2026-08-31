import { Box, Button, Card, CardContent, Chip, Divider, Stack, Typography } from '@mui/material'
import StorefrontRoundedIcon from '@mui/icons-material/StorefrontRounded'
import MedicationRoundedIcon from '@mui/icons-material/MedicationRounded'
import Inventory2RoundedIcon from '@mui/icons-material/Inventory2Rounded'
import PhonelinkRingRoundedIcon from '@mui/icons-material/PhonelinkRingRounded'
import RuleRoundedIcon from '@mui/icons-material/RuleRounded'
import CompareArrowsRoundedIcon from '@mui/icons-material/CompareArrowsRounded'
import TuneRoundedIcon from '@mui/icons-material/TuneRounded'
import FactCheckRoundedIcon from '@mui/icons-material/FactCheckRounded'
import UploadFileRoundedIcon from '@mui/icons-material/UploadFileRounded'
import AssessmentRoundedIcon from '@mui/icons-material/AssessmentRounded'
import ArrowForwardRoundedIcon from '@mui/icons-material/ArrowForwardRounded'
import type { SvgIconComponent } from '@mui/icons-material'
import { useQuery } from '@tanstack/react-query'
import { Link as RouterLink } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import { EmptyState, ErrorState, LoadingState } from '@/components/states'
import { VarianceValue } from '@/components/VarianceValue'
import { useAuth } from '@/features/auth/AuthContext'
import { apiErrorMessage, get } from '@/services/apiClient'
import { formatNumber, formatRelative } from '@/utils/format'
import type { DashboardSummary } from '@/types'
import { brand, neutral, semantic, variance as varianceColour } from '@/theme'

export function DashboardPage() {
  const { user, can } = useAuth()

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['dashboard-summary'],
    queryFn: async () => (await get<DashboardSummary>('/dashboard/summary')).data,
  })

  if (isLoading) return <LoadingState label="Loading your dashboard…" height={420} />
  if (isError || !data) {
    return <ErrorState message={apiErrorMessage(error)} onRetry={() => void refetch()} />
  }

  const { cards, variance_breakdown: variance } = data

  const metrics: Array<{
    label: string
    value: number
    icon: SvgIconComponent
    to: string
    tone: 'neutral' | 'attention' | 'positive'
    hint?: string
  }> = [
    { label: 'Active Shops', value: cards.total_shops, icon: StorefrontRoundedIcon, to: '/shops', tone: 'neutral' },
    { label: 'Active Items', value: cards.total_items, icon: MedicationRoundedIcon, to: '/items', tone: 'neutral' },
    { label: 'Stock Records', value: cards.stock_records, icon: Inventory2RoundedIcon, to: '/item-stock', tone: 'neutral' },
    {
      label: 'Submissions Today',
      value: cards.hht_submissions_today,
      icon: PhonelinkRingRoundedIcon,
      to: '/hht',
      tone: 'neutral',
    },
    {
      label: 'Pending Verification',
      value: cards.pending_verification,
      icon: RuleRoundedIcon,
      to: '/verification',
      tone: cards.pending_verification > 0 ? 'attention' : 'positive',
      hint: 'Counted lines awaiting review',
    },
    {
      label: 'Variance Items',
      value: cards.variance_items,
      icon: CompareArrowsRoundedIcon,
      to: '/stock-adjustment',
      tone: cards.variance_items > 0 ? 'attention' : 'positive',
      hint: 'Physical count differs from system',
    },
    {
      label: 'Pending Adjustments',
      value: cards.pending_adjustments,
      icon: TuneRoundedIcon,
      to: '/stock-adjustment-log',
      tone: cards.pending_adjustments > 0 ? 'attention' : 'positive',
      hint: 'Variance not yet posted to stock',
    },
    {
      label: 'Completed Audits',
      value: cards.completed_audits,
      icon: FactCheckRoundedIcon,
      to: '/audits',
      tone: 'positive',
    },
  ]

  const quickActions = [
    { label: 'Import Stock', to: '/stock-import', icon: UploadFileRoundedIcon, permission: 'stock.import' },
    { label: 'View Audits', to: '/audits', icon: FactCheckRoundedIcon, permission: 'audits.view' },
    { label: 'Verify Stock', to: '/verification', icon: RuleRoundedIcon, permission: 'audits.view' },
    { label: 'Stock Adjustment', to: '/stock-adjustment', icon: CompareArrowsRoundedIcon, permission: 'variance.view' },
    { label: 'Stock Adj (Audit Log)', to: '/stock-adjustment-log', icon: TuneRoundedIcon, permission: 'adjustments.view' },
    { label: 'Generate Reports', to: '/reports', icon: AssessmentRoundedIcon, permission: 'reports.view' },
  ].filter((action) => can(action.permission))

  const varianceTotal = variance.short + variance.excess + variance.matched || 1

  return (
    <Box>
      <PageHeader
        title={`Good ${greeting()}, ${user?.name?.split(' ')[0] ?? 'there'}`}
        description="Where the stock verification currently stands across the shops you can see."
      />

      {/* Metric cards */}
      <Box
        sx={{
          display: 'grid',
          gap: 2,
          gridTemplateColumns: { xs: '1fr', sm: 'repeat(2, 1fr)', lg: 'repeat(4, 1fr)' },
          mb: 3,
        }}
      >
        {metrics.map((metric) => {
          const Icon = metric.icon

          const accent =
            metric.tone === 'attention' ? semantic.warning.fg : metric.tone === 'positive' ? semantic.success.fg : brand[600]

          return (
            <Card
              key={metric.label}
              component={RouterLink}
              to={metric.to}
              sx={{
                textDecoration: 'none',
                transition: 'border-color .16s, box-shadow .16s',
                '&:hover': { borderColor: accent, boxShadow: '0 4px 14px rgba(18,32,30,.07)' },
              }}
            >
              <CardContent sx={{ p: 2.25 }}>
                <Stack direction="row" justifyContent="space-between" alignItems="flex-start">
                  <Box sx={{ minWidth: 0 }}>
                    <Typography variant="caption" sx={{ display: 'block', mb: 0.5 }}>
                      {metric.label}
                    </Typography>
                    <Typography sx={{ fontSize: '1.75rem', fontWeight: 700, lineHeight: 1.1, color: 'text.primary' }}>
                      {formatNumber(metric.value)}
                    </Typography>
                  </Box>

                  <Box
                    sx={{
                      width: 38,
                      height: 38,
                      borderRadius: 2,
                      display: 'grid',
                      placeItems: 'center',
                      bgcolor: `${accent}14`,
                      color: accent,
                      flexShrink: 0,
                    }}
                  >
                    <Icon fontSize="small" />
                  </Box>
                </Stack>

                {metric.hint ? (
                  <Typography variant="caption" sx={{ display: 'block', mt: 1 }}>
                    {metric.hint}
                  </Typography>
                ) : null}
              </CardContent>
            </Card>
          )
        })}
      </Box>

      {/* Quick actions */}
      {quickActions.length > 0 ? (
        <Card sx={{ mb: 3 }}>
          <CardContent sx={{ p: 2.25 }}>
            <Typography variant="subtitle1" sx={{ mb: 1.75 }}>
              Quick actions
            </Typography>
            <Stack direction="row" sx={{ flexWrap: 'wrap', gap: 1.25 }}>
              {quickActions.map((action) => {
                const Icon = action.icon

                return (
                  <Button
                    key={action.to}
                    component={RouterLink}
                    to={action.to}
                    variant="outlined"
                    size="small"
                    startIcon={<Icon fontSize="small" />}
                    sx={{ borderColor: 'divider', color: 'text.primary' }}
                  >
                    {action.label}
                  </Button>
                )
              })}
            </Stack>
          </CardContent>
        </Card>
      ) : null}

      <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', lg: '1fr 1fr' } }}>
        {/* Variance breakdown */}
        <Card>
          <CardContent sx={{ p: 2.25 }}>
            <Typography variant="subtitle1" sx={{ mb: 0.5 }}>
              Variance across counted lines
            </Typography>
            <Typography variant="caption" sx={{ display: 'block', mb: 2 }}>
              System stock compared with what was counted, whole units and loose
            </Typography>

            <Box sx={{ display: 'flex', height: 10, borderRadius: 5, overflow: 'hidden', mb: 2 }}>
              {[
                { value: variance.short, colour: varianceColour.short },
                { value: variance.excess, colour: varianceColour.excess },
                { value: variance.matched, colour: neutral[300] },
              ].map((segment, index) => (
                <Box
                  key={index}
                  sx={{ width: `${(segment.value / varianceTotal) * 100}%`, bgcolor: segment.colour }}
                />
              ))}
            </Box>

            <Stack spacing={1.25}>
              {[
                { label: 'Short', value: variance.short, colour: varianceColour.short },
                { label: 'Excess', value: variance.excess, colour: varianceColour.excess },
                { label: 'Matched', value: variance.matched, colour: varianceColour.matched },
              ].map((row) => (
                <Stack key={row.label} direction="row" alignItems="center" spacing={1.25}>
                  <Box sx={{ width: 9, height: 9, borderRadius: '50%', bgcolor: row.colour, flexShrink: 0 }} />
                  <Typography variant="body2" sx={{ flexGrow: 1 }}>
                    {row.label}
                  </Typography>
                  <Typography variant="body2" sx={{ fontWeight: 700 }}>
                    {formatNumber(row.value)}
                  </Typography>
                </Stack>
              ))}
            </Stack>

            <Button
              component={RouterLink}
              to="/stock-adjustment"
              size="small"
              endIcon={<ArrowForwardRoundedIcon fontSize="small" />}
              sx={{ mt: 2 }}
            >
              Open Stock Adjustment
            </Button>
          </CardContent>
        </Card>

        {/* Recent HHT submissions */}
        <Card>
          <CardContent sx={{ p: 2.25 }}>
            <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 1.5 }}>
              <Typography variant="subtitle1">Recent HHT submissions</Typography>
              <Button component={RouterLink} to="/hht" size="small">
                View all
              </Button>
            </Stack>

            {data.recent_submissions.length === 0 ? (
              <EmptyState
                compact
                title="No submissions yet"
                description="Completed counts from the handheld devices will appear here."
              />
            ) : (
              <Stack divider={<Divider flexItem />}>
                {data.recent_submissions.map((submission) => (
                  <Stack
                    key={submission.id}
                    direction="row"
                    alignItems="center"
                    spacing={1.5}
                    sx={{ py: 1.25 }}
                  >
                    <Box sx={{ minWidth: 0, flexGrow: 1 }}>
                      <Typography variant="body2" sx={{ fontWeight: 600 }} noWrap>
                        {submission.shop_code} · {submission.device_code} · Audit {submission.audit_number}
                      </Typography>
                      <Typography variant="caption">
                        {submission.item_count} items · {formatRelative(submission.received_at)}
                        {submission.hht_user ? ` · ${submission.hht_user}` : ''}
                      </Typography>
                    </Box>
                    <StatusBadge status={submission.status} />
                  </Stack>
                ))}
              </Stack>
            )}
          </CardContent>
        </Card>

        {/* Recent audits */}
        <Card>
          <CardContent sx={{ p: 2.25 }}>
            <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 1.5 }}>
              <Typography variant="subtitle1">Recent audits</Typography>
              <Button component={RouterLink} to="/audits" size="small">
                View all
              </Button>
            </Stack>

            {data.recent_audits.length === 0 ? (
              <EmptyState compact title="No audits yet" description="Audits appear once a count is submitted." />
            ) : (
              <Stack divider={<Divider flexItem />}>
                {data.recent_audits.map((audit) => (
                  <Stack
                    key={audit.id}
                    direction="row"
                    alignItems="center"
                    spacing={1.5}
                    component={RouterLink}
                    to={`/audits/${audit.id}`}
                    sx={{ py: 1.25, textDecoration: 'none', color: 'inherit' }}
                  >
                    <Box sx={{ minWidth: 0, flexGrow: 1 }}>
                      <Typography variant="body2" sx={{ fontWeight: 600 }} noWrap>
                        {audit.shop_code} · {audit.device_code} · Audit {audit.audit_number}
                      </Typography>
                      <Typography variant="caption">
                        {audit.item_count} items · {audit.variance_count} with variance ·{' '}
                        {formatRelative(audit.submitted_at)}
                      </Typography>
                    </Box>
                    <StatusBadge status={audit.status} />
                  </Stack>
                ))}
              </Stack>
            )}
          </CardContent>
        </Card>

        {/* Recent adjustments */}
        <Card>
          <CardContent sx={{ p: 2.25 }}>
            <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 1.5 }}>
              <Typography variant="subtitle1">Recent adjustments</Typography>
              <Button component={RouterLink} to="/stock-adjustment-log" size="small">
                View all
              </Button>
            </Stack>

            {data.recent_adjustments.length === 0 ? (
              <EmptyState
                compact
                title="No adjustments posted"
                description="Posted adjustments and their history appear here."
              />
            ) : (
              <Stack divider={<Divider flexItem />}>
                {data.recent_adjustments.map((adjustment) => (
                  <Stack key={adjustment.id} direction="row" alignItems="center" spacing={1.5} sx={{ py: 1.25 }}>
                    <Box sx={{ minWidth: 0, flexGrow: 1 }}>
                      <Typography variant="body2" sx={{ fontWeight: 600 }} noWrap>
                        {adjustment.description ?? adjustment.product_code}
                      </Typography>
                      <Typography variant="caption">
                        {adjustment.shop_code} · {adjustment.old_system_qty} → {adjustment.new_system_qty} ·{' '}
                        {formatRelative(adjustment.adjusted_at)}
                      </Typography>
                    </Box>
                    <Chip
                      size="small"
                      label={<VarianceValue value={adjustment.variance_qty} />}
                      sx={{ bgcolor: 'transparent' }}
                    />
                  </Stack>
                ))}
              </Stack>
            )}
          </CardContent>
        </Card>
      </Box>
    </Box>
  )
}

function greeting(): string {
  const hour = new Date().getHours()

  if (hour < 12) return 'morning'
  if (hour < 17) return 'afternoon'
  return 'evening'
}
