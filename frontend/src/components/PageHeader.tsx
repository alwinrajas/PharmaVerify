import { Box, Breadcrumbs, Link, Stack, Typography } from '@mui/material'
import NavigateNextRoundedIcon from '@mui/icons-material/NavigateNextRounded'
import type { ReactNode } from 'react'
import { Link as RouterLink } from 'react-router-dom'

export interface Crumb {
  label: string
  to?: string
}

/**
 * The heading block every screen opens with: where you are, what this screen
 * is for, and the actions available on it.
 */
export function PageHeader({
  title,
  description,
  crumbs = [],
  actions,
}: {
  title: string
  description?: string
  crumbs?: Crumb[]
  actions?: ReactNode
}) {
  return (
    <Box sx={{ mb: 3 }}>
      {crumbs.length > 0 ? (
        <Breadcrumbs
          separator={<NavigateNextRoundedIcon fontSize="small" />}
          sx={{ mb: 1, '& .MuiBreadcrumbs-li': { fontSize: '0.8125rem' } }}
        >
          {crumbs.map((crumb) =>
            crumb.to ? (
              <Link
                key={crumb.label}
                component={RouterLink}
                to={crumb.to}
                underline="hover"
                color="text.secondary"
                sx={{ fontSize: '0.8125rem' }}
              >
                {crumb.label}
              </Link>
            ) : (
              <Typography key={crumb.label} variant="body2" color="text.primary" sx={{ fontWeight: 600 }}>
                {crumb.label}
              </Typography>
            ),
          )}
        </Breadcrumbs>
      ) : null}

      <Stack
        direction={{ xs: 'column', md: 'row' }}
        justifyContent="space-between"
        alignItems={{ xs: 'flex-start', md: 'center' }}
        spacing={2}
      >
        <Box>
          <Typography variant="h1">{title}</Typography>
          {description ? (
            <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5, maxWidth: 720 }}>
              {description}
            </Typography>
          ) : null}
        </Box>

        {actions ? (
          <Stack direction="row" spacing={1.25} sx={{ flexShrink: 0, flexWrap: 'wrap', gap: 1 }}>
            {actions}
          </Stack>
        ) : null}
      </Stack>
    </Box>
  )
}
