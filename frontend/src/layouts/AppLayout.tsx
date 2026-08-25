import {
  AppBar,
  Avatar,
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
import { Outlet, useNavigate } from 'react-router-dom'
import { useAuth } from '@/features/auth/AuthContext'
import { SIDEBAR_WIDTH, Sidebar } from './Sidebar'

export function AppLayout() {
  const { user, signOut } = useAuth()
  const navigate = useNavigate()

  const [mobileOpen, setMobileOpen] = useState(false)
  const [menuAnchor, setMenuAnchor] = useState<HTMLElement | null>(null)

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
        sx={{ width: { lg: SIDEBAR_WIDTH }, flexShrink: { lg: 0 }, display: { xs: 'none', lg: 'block' } }}
      >
        <Box sx={{ position: 'fixed', top: 0, bottom: 0, width: SIDEBAR_WIDTH }}>
          <Sidebar />
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
          <Toolbar sx={{ gap: 1.5, minHeight: { xs: 58, sm: 62 } }}>
            <IconButton
              onClick={() => setMobileOpen(true)}
              edge="start"
              sx={{ display: { lg: 'none' } }}
              aria-label="Open navigation"
            >
              <MenuRoundedIcon />
            </IconButton>

            <Box sx={{ flexGrow: 1, minWidth: 0 }}>
              <Typography variant="subtitle1" noWrap sx={{ display: { xs: 'none', sm: 'block' } }}>
                Pharmacy Stock Verification
              </Typography>
              <Typography variant="caption" sx={{ display: { xs: 'none', md: 'block' } }}>
                Master → Stock → HHT → Audit → Verification → Variance → Adjustment → Reports
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

            <IconButton onClick={(event) => setMenuAnchor(event.currentTarget)} size="small" aria-label="Account">
              <Avatar sx={{ width: 34, height: 34, bgcolor: 'primary.main', fontSize: '0.8125rem', fontWeight: 700 }}>
                {initials}
              </Avatar>
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

        <Box component="main" sx={{ flexGrow: 1, p: { xs: 2, sm: 3 }, maxWidth: 1600, width: '100%', mx: 'auto' }}>
          <Outlet />
        </Box>
      </Box>
    </Box>
  )
}
