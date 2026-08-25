import { Box, Divider, List, ListItemButton, ListItemIcon, ListItemText, Stack, Typography } from '@mui/material'
import { NavLink, useLocation } from 'react-router-dom'
import { useAuth } from '@/features/auth/AuthContext'
import { navigation } from './navigation'

export const SIDEBAR_WIDTH = 254

export function Sidebar({ onNavigate }: { onNavigate?: () => void }) {
  const { can, user } = useAuth()
  const location = useLocation()

  return (
    <Box
      sx={{
        width: SIDEBAR_WIDTH,
        flexShrink: 0,
        height: '100%',
        bgcolor: '#0B3B31',
        color: 'rgba(255,255,255,0.86)',
        display: 'flex',
        flexDirection: 'column',
      }}
    >
      <Stack direction="row" spacing={1.5} alignItems="center" sx={{ px: 2.5, py: 2.5 }}>
        <Box
          component="img"
          src="/pharmaverify.svg"
          alt=""
          sx={{ width: 34, height: 34, borderRadius: 2, flexShrink: 0 }}
        />
        <Box sx={{ minWidth: 0 }}>
          <Typography sx={{ color: '#fff', fontWeight: 700, fontSize: '1rem', lineHeight: 1.2 }}>
            PharmaVerify
          </Typography>
          <Typography sx={{ color: 'rgba(255,255,255,0.55)', fontSize: '0.6875rem', letterSpacing: '0.06em' }}>
            STOCK VERIFICATION
          </Typography>
        </Box>
      </Stack>

      <Divider sx={{ borderColor: 'rgba(255,255,255,0.09)' }} />

      <Box sx={{ flex: 1, overflowY: 'auto', px: 1.25, py: 1.5 }}>
        {navigation.map((section, index) => {
          const visibleItems = section.items.filter((item) => !item.permission || can(item.permission))

          if (visibleItems.length === 0) return null

          return (
            <Box key={section.heading ?? `section-${index}`} sx={{ mb: 1.5 }}>
              {section.heading ? (
                <Typography
                  sx={{
                    px: 1.5,
                    pt: 1,
                    pb: 0.75,
                    fontSize: '0.6875rem',
                    fontWeight: 700,
                    letterSpacing: '0.08em',
                    color: 'rgba(255,255,255,0.4)',
                  }}
                >
                  {section.heading.toUpperCase()}
                </Typography>
              ) : null}

              <List disablePadding>
                {visibleItems.map((item) => {
                  const active =
                    item.to === '/'
                      ? location.pathname === '/'
                      : location.pathname === item.to || location.pathname.startsWith(`${item.to}/`)

                  const Icon = item.icon

                  return (
                    <ListItemButton
                      key={item.to}
                      component={NavLink}
                      to={item.to}
                      onClick={onNavigate}
                      sx={{
                        borderRadius: 2,
                        mb: 0.25,
                        minHeight: 40,
                        px: 1.5,
                        color: active ? '#fff' : 'rgba(255,255,255,0.78)',
                        bgcolor: active ? 'rgba(46, 158, 126, 0.22)' : 'transparent',
                        position: 'relative',
                        '&:hover': { bgcolor: active ? 'rgba(46, 158, 126, 0.26)' : 'rgba(255,255,255,0.06)' },
                        '&::before': active
                          ? {
                              content: '""',
                              position: 'absolute',
                              left: 0,
                              top: 8,
                              bottom: 8,
                              width: 3,
                              borderRadius: 3,
                              bgcolor: '#4FD1A5',
                            }
                          : undefined,
                      }}
                    >
                      <ListItemIcon sx={{ minWidth: 32, color: active ? '#4FD1A5' : 'rgba(255,255,255,0.6)' }}>
                        <Icon fontSize="small" />
                      </ListItemIcon>
                      <ListItemText
                        primary={item.label}
                        slotProps={{
                          primary: { fontSize: '0.8438rem', fontWeight: active ? 600 : 500 },
                        }}
                      />
                    </ListItemButton>
                  )
                })}
              </List>
            </Box>
          )
        })}
      </Box>

      <Divider sx={{ borderColor: 'rgba(255,255,255,0.09)' }} />

      <Box sx={{ px: 2.5, py: 1.75 }}>
        <Typography sx={{ color: 'rgba(255,255,255,0.8)', fontSize: '0.8125rem', fontWeight: 600 }} noWrap>
          {user?.name}
        </Typography>
        <Typography sx={{ color: 'rgba(255,255,255,0.45)', fontSize: '0.6875rem' }} noWrap>
          {user?.roles.join(', ')}
        </Typography>
      </Box>
    </Box>
  )
}
