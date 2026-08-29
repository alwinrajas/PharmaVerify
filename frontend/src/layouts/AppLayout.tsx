import {
  AppBar,
  Avatar,
  Badge,
  Box,
  Chip,
  Divider,
  Drawer,
  IconButton,
  ListItemIcon,
  Menu,
  MenuItem,
  Toolbar,
  Tooltip,
  Typography,
} from '@mui/material'
import MenuRoundedIcon from '@mui/icons-material/MenuRounded'
import LogoutRoundedIcon from '@mui/icons-material/LogoutRounded'
import PersonRoundedIcon from '@mui/icons-material/PersonRounded'
import StorefrontRoundedIcon from '@mui/icons-material/StorefrontRounded'
import { useState } from 'react'
import { Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '@/features/auth/AuthContext'
import { SIDEBAR_RAIL_WIDTH, SIDEBAR_WIDTH, Sidebar } from './Sidebar'
import { resolveScreen } from './navigation'

export function AppLayout() {
  const { user, signOut } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()

  // The bar is sticky, so it keeps naming the screen once the page heading has
  // scrolled out of view.
  const screen = resolveScreen(location.pathname)
  const ScreenIcon = screen.icon

  const [mobileOpen, setMobileOpen] = useState(false)
  const [menuAnchor, setMenuAnchor] = useState<HTMLElement | null>(null)

  // Remembered per browser so the choice survives a reload. Storage can throw
  // in a private window, so every access is guarded.
  const [collapsed, setCollapsed] = useState(() => {
    try {
      return localStorage.getItem('pharmaverify.nav.collapsed') === '1'
    } catch {
      return false
    }
  })

  function toggleCollapsed() {
    setCollapsed((current) => {
      const next = !current
      try {
        localStorage.setItem('pharmaverify.nav.collapsed', next ? '1' : '0')
      } catch {
        /* a preference is not worth failing a render over */
      }
      return next
    })
  }

  const navWidth = collapsed ? SIDEBAR_RAIL_WIDTH : SIDEBAR_WIDTH

  async function handleSignOut() {
    setMenuAnchor(null)
    await signOut()
    navigate('/login', { replace: true })
  }

  const assignedShops = user?.shops ?? []
  const initials = (user?.name ?? '?')
    .split(' ')
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('')

  return (
    <Box sx={{ display: 'flex', minHeight: '100vh', bgcolor: 'background.default' }}>
      {/* Permanent sidebar from the large breakpoint up. */}
      <Box
        component="nav"
        sx={{
          width: { lg: navWidth },
          flexShrink: { lg: 0 },
          display: { xs: 'none', lg: 'block' },
          transition: 'width 160ms ease',
        }}
      >
        <Box sx={{ position: 'fixed', top: 0, bottom: 0, width: navWidth, transition: 'width 160ms ease' }}>
          <Sidebar collapsed={collapsed} onToggleCollapse={toggleCollapsed} />
        </Box>
      </Box>

      <Drawer
        variant="temporary"
        open={mobileOpen}
        onClose={() => setMobileOpen(false)}
        ModalProps={{ keepMounted: true }}
        sx={{ display: { xs: 'block', lg: 'none' }, '& .MuiDrawer-paper': { border: 0 } }}
      >
        <Sidebar onNavigate={() => setMobileOpen(false)} />
      </Drawer>

      <Box sx={{ flexGrow: 1, minWidth: 0, display: 'flex', flexDirection: 'column' }}>
        <AppBar
          position="sticky"
          elevation={0}
          sx={{
            bgcolor: 'rgba(255,255,255,0.92)',
            backdropFilter: 'blur(8px)',
            color: 'text.primary',
            borderBottom: 1,
            borderColor: 'divider',
          }}
        >
          <Toolbar sx={{ gap: 1.5, minHeight: { xs: 56, sm: 58 } }}>
            <IconButton
              aria-label="Open navigation menu"
              onClick={() => setMobileOpen(true)}
              edge="start"
              sx={{ display: { lg: 'none' } }}
            >
              <MenuRoundedIcon />
            </IconButton>

            <Box
              sx={{
                width: 34,
                height: 34,
                borderRadius: 1.5,
                display: { xs: 'none', sm: 'grid' },
                placeItems: 'center',
                bgcolor: 'rgba(15, 93, 76, 0.08)',
                color: 'primary.main',
                flexShrink: 0,
              }}
            >
              <ScreenIcon fontSize="small" />
            </Box>

            <Box sx={{ flexGrow: 1, minWidth: 0 }}>
              <Typography
                sx={{ fontSize: '0.9375rem', fontWeight: 600, lineHeight: 1.2 }}
                noWrap
              >
                {screen.label}
              </Typography>
              <Typography variant="caption" noWrap sx={{ display: { xs: 'none', md: 'block' } }}>
                {screen.context ? `${screen.section} · ${screen.context}` : screen.section}
              </Typography>
            </Box>

            {assignedShops.length > 0 ? (
              <Tooltip title={assignedShops.map((shop) => `${shop.shop_code} — ${shop.shop_name}`).join('\n')}>
                <Chip
                  icon={<StorefrontRoundedIcon fontSize="small" />}
                  size="small"
                  label={
                    assignedShops.length === 1
                      ? assignedShops[0].shop_code
                      : `${assignedShops.length} shops assigned`
                  }
                  sx={{ display: { xs: 'none', sm: 'inline-flex' }, bgcolor: 'rgba(15,93,76,0.08)', color: 'primary.main' }}
                />
              </Tooltip>
            ) : null}

            <IconButton
              onClick={(event) => setMenuAnchor(event.currentTarget)}
              size="small"
              aria-label="Account and sign out"
              sx={{ ml: 0.25 }}
            >
              <Badge
                overlap="circular"
                anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }}
                variant="dot"
                sx={{
                  '& .MuiBadge-dot': {
                    bgcolor: 'success.main',
                    boxShadow: '0 0 0 2px #fff',
                    height: 9,
                    minWidth: 9,
                    borderRadius: '50%',
                  },
                }}
              >
                <Avatar
                  sx={{ width: 32, height: 32, bgcolor: 'primary.main', fontSize: '0.75rem', fontWeight: 700 }}
                >
                  {initials}
                </Avatar>
              </Badge>
            </IconButton>

            <Menu
              anchorEl={menuAnchor}
              open={Boolean(menuAnchor)}
              onClose={() => setMenuAnchor(null)}
              anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }}
              transformOrigin={{ vertical: 'top', horizontal: 'right' }}
              slotProps={{ paper: { sx: { minWidth: 232, borderRadius: 2.5, mt: 0.5 } } }}
            >
              <Box sx={{ px: 2, py: 1.5 }}>
                <Typography variant="subtitle2" color="text.primary">
                  {user?.name}
                </Typography>
                <Typography variant="caption">{user?.email}</Typography>
                <Box sx={{ mt: 1 }}>
                  {user?.roles.map((role) => (
                    <Chip
                      key={role}
                      size="small"
                      label={role}
                      icon={<PersonRoundedIcon fontSize="small" />}
                      sx={{ bgcolor: 'rgba(15,93,76,0.08)', color: 'primary.main' }}
                    />
                  ))}
                </Box>
              </Box>

              <Divider />

              <MenuItem onClick={handleSignOut} sx={{ py: 1.25 }}>
                <ListItemIcon>
                  <LogoutRoundedIcon fontSize="small" />
                </ListItemIcon>
                Sign out
              </MenuItem>
            </Menu>
          </Toolbar>
        </AppBar>

        <Box
          component="main"
          sx={{ flexGrow: 1, px: { xs: 2, sm: 2.5 }, py: { xs: 2, sm: 2.5 }, maxWidth: 1720, width: '100%', mx: 'auto' }}
        >
          <Outlet />
        </Box>
      </Box>
    </Box>
  )
}
