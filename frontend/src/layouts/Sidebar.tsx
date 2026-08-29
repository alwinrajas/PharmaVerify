import {
  Avatar,
  Box,
  Divider,
  IconButton,
  List,
  ListItemButton,
  ListItemIcon,
  ListItemText,
  Tooltip,
  Typography,
} from '@mui/material'
import ChevronRightRoundedIcon from '@mui/icons-material/ChevronRightRounded'
import KeyboardDoubleArrowLeftRoundedIcon from '@mui/icons-material/KeyboardDoubleArrowLeftRounded'
import KeyboardDoubleArrowRightRoundedIcon from '@mui/icons-material/KeyboardDoubleArrowRightRounded'
import { NavLink, useLocation } from 'react-router-dom'
import { useAuth } from '@/features/auth/AuthContext'
import { Logo, LogoMark } from '@/components/Logo'
import { navigation } from './navigation'
import { SidebarPattern } from './SidebarPattern'
import { brand } from '@/theme'

export const SIDEBAR_WIDTH = 254
export const SIDEBAR_RAIL_WIDTH = 72

/**
 * The application's primary navigation.
 *
 * A deep brand field with just enough gradient to give the panel depth — the
 * eye reads it as a surface rather than a flat block, without anything that
 * announces itself as a gradient. Everything else here is restraint: the
 * active item is marked by a rail, a tint and weight rather than a glow.
 */
export function Sidebar({
  onNavigate,
  collapsed = false,
  onToggleCollapse,
}: {
  onNavigate?: () => void
  collapsed?: boolean
  onToggleCollapse?: () => void
}) {
  const { can, user } = useAuth()
  const location = useLocation()

  const initials = (user?.name ?? '')
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('')

  return (
    <Box
      sx={{
        width: collapsed ? SIDEBAR_RAIL_WIDTH : SIDEBAR_WIDTH,
        flexShrink: 0,
        height: '100%',
        color: 'rgba(255,255,255,0.86)',
        display: 'flex',
        flexDirection: 'column',
        transition: 'width 160ms ease',
        // Depth without decoration: a single soft shift from the deep brand
        // tone into near-black at the foot of the panel, with the drawn
        // pattern layered on top of it.
        background: `linear-gradient(178deg, ${brand[900]} 0%, ${brand.deep} 100%)`,
        borderRight: '1px solid rgba(255,255,255,0.07)',
        position: 'relative',
        overflow: 'hidden',
        // Everything below sits above the decorative field.
        '& > *:not(.pv-sidebar-pattern)': { position: 'relative', zIndex: 1 },
      }}
    >
      <SidebarPattern />
      {/* ---------------------------------------------------------- brand */}
      <Box
        sx={{
          display: 'flex',
          alignItems: 'center',
          justifyContent: collapsed ? 'center' : 'space-between',
          gap: 1,
          px: collapsed ? 0 : 2.25,
          py: 2,
          minHeight: 68,
        }}
      >
        {collapsed ? <LogoMark size={32} /> : <Logo size={34} />}

        {onToggleCollapse && !collapsed ? (
          <IconButton
            aria-label="Collapse navigation"
            onClick={onToggleCollapse}
            size="small"
            sx={{ color: 'rgba(255,255,255,0.45)', '&:hover': { color: '#fff', bgcolor: 'rgba(255,255,255,0.07)' } }}
          >
            <KeyboardDoubleArrowLeftRoundedIcon fontSize="small" />
          </IconButton>
        ) : null}
      </Box>

      <Divider sx={{ borderColor: 'rgba(255,255,255,0.07)' }} />

      {/* ----------------------------------------------------- navigation */}
      <Box sx={{ flex: 1, overflowY: 'auto', overflowX: 'hidden', px: collapsed ? 1 : 1.25, py: 1.25 }}>
        {navigation.map((section, index) => {
          const visibleItems = section.items.filter((item) => !item.permission || can(item.permission))

          if (visibleItems.length === 0) return null

          return (
            <Box key={section.heading ?? `section-${index}`} sx={{ mb: 1.25 }}>
              {section.heading && !collapsed ? (
                <Typography
                  sx={{
                    px: 1.5,
                    pt: 1.25,
                    pb: 0.75,
                    fontSize: '0.625rem',
                    fontWeight: 700,
                    letterSpacing: '0.11em',
                    color: 'rgba(255,255,255,0.34)',
                  }}
                >
                  {section.heading.toUpperCase()}
                </Typography>
              ) : null}

              {section.heading && collapsed && index > 0 ? (
                <Divider sx={{ borderColor: 'rgba(255,255,255,0.07)', my: 1 }} />
              ) : null}

              <List disablePadding>
                {visibleItems.map((item) => {
                  const active =
                    item.to === '/'
                      ? location.pathname === '/'
                      : location.pathname === item.to || location.pathname.startsWith(`${item.to}/`)

                  const Icon = item.icon

                  const button = (
                    <ListItemButton
                      key={item.to}
                      component={NavLink}
                      to={item.to}
                      onClick={onNavigate}
                      sx={{
                        borderRadius: 1.5,
                        mb: 0.25,
                        minHeight: 38,
                        px: collapsed ? 0 : 1.5,
                        justifyContent: collapsed ? 'center' : 'flex-start',
                        color: active ? '#fff' : 'rgba(255,255,255,0.72)',
                        // A tint, a hairline and a rail — enough to read as
                        // selected, nothing that glows.
                        bgcolor: active ? 'rgba(79, 209, 165, 0.14)' : 'transparent',
                        boxShadow: active ? 'inset 0 0 0 1px rgba(79, 209, 165, 0.22)' : 'none',
                        position: 'relative',
                        transition: 'background-color 120ms ease, color 120ms ease',
                        '&:hover': {
                          bgcolor: active ? 'rgba(79, 209, 165, 0.18)' : 'rgba(255,255,255,0.055)',
                          color: '#fff',
                        },
                        '&::before':
                          active && !collapsed
                            ? {
                                content: '""',
                                position: 'absolute',
                                left: 0,
                                top: 9,
                                bottom: 9,
                                width: 3,
                                borderRadius: '0 3px 3px 0',
                                bgcolor: brand.accent,
                              }
                            : undefined,
                      }}
                    >
                      <ListItemIcon
                        sx={{
                          minWidth: collapsed ? 0 : 30,
                          justifyContent: 'center',
                          color: active ? brand.accent : 'rgba(255,255,255,0.55)',
                        }}
                      >
                        <Icon fontSize="small" />
                      </ListItemIcon>

                      {collapsed ? null : (
                        <>
                          <ListItemText
                            primary={item.label}
                            slotProps={{ primary: { fontSize: '0.8438rem', fontWeight: active ? 600 : 500 } }}
                          />
                          {/* Marks the screen you are on, the way the
                              reference does — a quiet "you are here". */}
                          {active ? (
                            <ChevronRightRoundedIcon
                              fontSize="small"
                              sx={{ color: brand.accent, opacity: 0.75, ml: 0.5, flexShrink: 0 }}
                            />
                          ) : null}
                        </>
                      )}
                    </ListItemButton>
                  )

                  return collapsed ? (
                    <Tooltip key={item.to} title={item.label} placement="right">
                      <Box>{button}</Box>
                    </Tooltip>
                  ) : (
                    button
                  )
                })}
              </List>
            </Box>
          )
        })}
      </Box>

      {/* ------------------------------------------------------- identity */}
      <Divider sx={{ borderColor: 'rgba(255,255,255,0.07)' }} />

      <Box sx={{ px: collapsed ? 1 : 1.5, py: 1.25 }}>
        <Box
          sx={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: collapsed ? 'center' : 'flex-start',
            gap: 1.25,
            px: collapsed ? 0 : 1,
            py: 0.875,
            borderRadius: 1.5,
            // A faint plate so the identity block reads as its own object
            // against the pattern rather than floating on it.
            bgcolor: collapsed ? 'transparent' : 'rgba(255,255,255,0.045)',
          }}
        >
          <Avatar
            sx={{
              width: 32,
              height: 32,
              bgcolor: 'rgba(79, 209, 165, 0.16)',
              color: brand.accent,
              fontSize: '0.75rem',
              fontWeight: 700,
              flexShrink: 0,
            }}
          >
            {initials || '—'}
          </Avatar>

          {collapsed ? null : (
            <>
              <Box sx={{ minWidth: 0, flexGrow: 1 }}>
                <Typography sx={{ color: 'rgba(255,255,255,0.92)', fontSize: '0.8125rem', fontWeight: 600 }} noWrap>
                  {user?.name}
                </Typography>
                <Typography sx={{ color: 'rgba(255,255,255,0.45)', fontSize: '0.6875rem' }} noWrap>
                  {user?.roles.join(', ')}
                </Typography>
              </Box>
            </>
          )}
        </Box>
      </Box>

      {collapsed && onToggleCollapse ? (
        <Box sx={{ display: 'flex', justifyContent: 'center', pb: 1.5 }}>
          <IconButton
            aria-label="Expand navigation"
            onClick={onToggleCollapse}
            size="small"
            sx={{ color: 'rgba(255,255,255,0.45)', '&:hover': { color: '#fff', bgcolor: 'rgba(255,255,255,0.07)' } }}
          >
            <KeyboardDoubleArrowRightRoundedIcon fontSize="small" />
          </IconButton>
        </Box>
      ) : (
        <Box
          sx={{
            px: 2.25,
            pb: 1.5,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: 1,
            fontSize: '0.6875rem',
            color: 'rgba(255,255,255,0.28)',
          }}
        >
          <Typography component="span" sx={{ fontSize: 'inherit', color: 'inherit' }} noWrap>
            PharmaVerify © 2026
          </Typography>
          <Typography component="span" sx={{ fontSize: 'inherit', color: 'inherit' }} noWrap>
            v0.2.6
          </Typography>
        </Box>
      )}
    </Box>
  )
}
