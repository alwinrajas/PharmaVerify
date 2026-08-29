import {
  Alert,
  Box,
  Button,
  Card,
  CardContent,
  Chip,
  Divider,
  Stack,
  TextField,
  Typography,
} from '@mui/material'
import SaveRoundedIcon from '@mui/icons-material/SaveRounded'
import CloudRoundedIcon from '@mui/icons-material/CloudRounded'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSnackbar } from 'notistack'
import { useEffect, useState } from 'react'
import { PageHeader } from '@/components/PageHeader'
import { ErrorState, LoadingState } from '@/components/states'
import { useAuth } from '@/features/auth/AuthContext'
import { apiErrorMessage, get, put } from '@/services/apiClient'
import { PERMISSIONS } from '@/constants/permissions'
import { neutral, semantic } from '@/theme'

interface SettingEntry {
  id: number
  key: string
  label: string | null
  description: string | null
  value: string | number | boolean | null
  type: string
  is_editable: boolean
}

interface SettingsResponse {
  groups: Record<string, SettingEntry[]>
  integrations: {
    onedrive_driver: string
    onedrive_folder: string
    onedrive_configured: boolean
  }
}

const GROUP_LABELS: Record<string, string> = {
  general: 'General',
  stock: 'Stock',
  verification: 'Verification',
  onedrive: 'OneDrive',
}

export function SettingsPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const { enqueueSnackbar } = useSnackbar()

  const [values, setValues] = useState<Record<string, string>>({})

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['settings'],
    queryFn: async () => (await get<SettingsResponse>('/settings')).data,
  })

  useEffect(() => {
    if (!data) return

    const next: Record<string, string> = {}

    Object.values(data.groups).forEach((entries) => {
      entries.forEach((entry) => {
        next[entry.key] = entry.value === null ? '' : String(entry.value)
      })
    })

    setValues(next)
  }, [data])

  const saveMutation = useMutation({
    mutationFn: async () =>
      put('/settings', {
        settings: Object.entries(values).map(([key, value]) => ({ key, value })),
      }),
    onSuccess: (response) => {
      enqueueSnackbar(response.message ?? 'Settings saved.', { variant: 'success' })
      void queryClient.invalidateQueries({ queryKey: ['settings'] })
    },
    onError: (caught) => enqueueSnackbar(apiErrorMessage(caught), { variant: 'error' }),
  })

  if (isLoading) return <LoadingState label="Loading settings…" height={340} />
  if (isError || !data) return <ErrorState message={apiErrorMessage(error)} onRetry={() => void refetch()} />

  const editable = can(PERMISSIONS.settingsManage)

  return (
    <Box>
      <PageHeader
        title="Settings"
        description="Application settings used across reports, stock handling and the OneDrive integration."
        crumbs={[{ label: 'Administration' }, { label: 'Settings' }]}
        actions={
          editable ? (
            <Button
              variant="contained"
              startIcon={<SaveRoundedIcon />}
              onClick={() => saveMutation.mutate()}
              disabled={saveMutation.isPending}
            >
              {saveMutation.isPending ? 'Saving…' : 'Save changes'}
            </Button>
          ) : null
        }
      />

      {!editable ? (
        <Alert severity="info" sx={{ mb: 2.5 }}>
          You can view these settings, but changing them requires the settings permission.
        </Alert>
      ) : null}

      <Box sx={{ display: 'grid', gap: 2.5, gridTemplateColumns: { xs: '1fr', lg: '1fr 1fr' }, alignItems: 'start' }}>
        {Object.entries(data.groups).map(([group, entries]) => (
          <Card key={group}>
            <CardContent sx={{ p: 2.5 }}>
              <Typography variant="subtitle1" sx={{ mb: 2 }}>
                {GROUP_LABELS[group] ?? group}
              </Typography>

              <Stack spacing={2.5}>
                {entries.map((entry) => (
                  <Box key={entry.key}>
                    <TextField
                      label={entry.label ?? entry.key}
                      value={values[entry.key] ?? ''}
                      onChange={(event) => setValues({ ...values, [entry.key]: event.target.value })}
                      type={entry.type === 'integer' ? 'number' : 'text'}
                      size="small"
                      fullWidth
                      disabled={!editable || !entry.is_editable}
                    />
                    {entry.description ? (
                      <Typography variant="caption" sx={{ display: 'block', mt: 0.5 }}>
                        {entry.description}
                      </Typography>
                    ) : null}
                  </Box>
                ))}
              </Stack>
            </CardContent>
          </Card>
        ))}

        {/* Integration status — read only, never exposes secrets. */}
        <Card>
          <CardContent sx={{ p: 2.5 }}>
            <Stack direction="row" spacing={1.25} alignItems="center" sx={{ mb: 2 }}>
              <CloudRoundedIcon fontSize="small" sx={{ color: 'primary.main' }} />
              <Typography variant="subtitle1">Integrations</Typography>
            </Stack>

            <Stack spacing={2} divider={<Divider flexItem />}>
              <Stack direction="row" justifyContent="space-between" alignItems="center">
                <Box>
                  <Typography variant="body2" sx={{ fontWeight: 600 }}>
                    OneDrive driver
                  </Typography>
                  <Typography variant="caption">
                    {data.integrations.onedrive_driver === 'graph'
                      ? 'Uploads go to Microsoft 365 through the Graph API.'
                      : 'Uploads are simulated locally so the flow can be demonstrated.'}
                  </Typography>
                </Box>
                <Chip
                  size="small"
                  label={data.integrations.onedrive_driver}
                  sx={
                    data.integrations.onedrive_driver === 'graph'
                      ? { bgcolor: semantic.success.bg, color: semantic.success.fg }
                      : { bgcolor: semantic.warning.bg, color: semantic.warning.fg }
                  }
                />
              </Stack>

              <Stack direction="row" justifyContent="space-between" alignItems="center">
                <Box>
                  <Typography variant="body2" sx={{ fontWeight: 600 }}>
                    Credentials configured
                  </Typography>
                  <Typography variant="caption">
                    Set in the environment file; never shown or editable here.
                  </Typography>
                </Box>
                <Chip
                  size="small"
                  label={data.integrations.onedrive_configured ? 'Yes' : 'Not yet'}
                  sx={
                    data.integrations.onedrive_configured
                      ? { bgcolor: semantic.success.bg, color: semantic.success.fg }
                      : { bgcolor: neutral[100], color: neutral[600] }
                  }
                />
              </Stack>

              <Stack direction="row" justifyContent="space-between" alignItems="center">
                <Box>
                  <Typography variant="body2" sx={{ fontWeight: 600 }}>
                    Destination folder
                  </Typography>
                  <Typography variant="caption">Where the final output file is written.</Typography>
                </Box>
                <Typography variant="body2" sx={{ fontFamily: 'ui-monospace, monospace', fontSize: '0.78rem' }}>
                  {data.integrations.onedrive_folder}
                </Typography>
              </Stack>
            </Stack>

            <Alert severity="info" sx={{ mt: 2.5 }}>
              Database credentials and integration secrets are held in the server's environment file. They are never
              returned by the API or shown in this screen.
            </Alert>
          </CardContent>
        </Card>
      </Box>
    </Box>
  )
}
