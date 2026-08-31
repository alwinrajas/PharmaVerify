import DashboardRoundedIcon from '@mui/icons-material/DashboardRounded'
import StorefrontRoundedIcon from '@mui/icons-material/StorefrontRounded'
import MedicationRoundedIcon from '@mui/icons-material/MedicationRounded'
import TabletAndroidRoundedIcon from '@mui/icons-material/TabletAndroidRounded'
import UploadFileRoundedIcon from '@mui/icons-material/UploadFileRounded'
import Inventory2RoundedIcon from '@mui/icons-material/Inventory2Rounded'
import PhonelinkRingRoundedIcon from '@mui/icons-material/PhonelinkRingRounded'
import FactCheckRoundedIcon from '@mui/icons-material/FactCheckRounded'
import TuneRoundedIcon from '@mui/icons-material/TuneRounded'
import HistoryEduRoundedIcon from '@mui/icons-material/HistoryEduRounded'
import RuleRoundedIcon from '@mui/icons-material/RuleRounded'
import PlaylistAddCheckRoundedIcon from '@mui/icons-material/PlaylistAddCheckRounded'
import AssessmentRoundedIcon from '@mui/icons-material/AssessmentRounded'
import CloudUploadRoundedIcon from '@mui/icons-material/CloudUploadRounded'
import GroupRoundedIcon from '@mui/icons-material/GroupRounded'
import SettingsRoundedIcon from '@mui/icons-material/SettingsRounded'
import HistoryRoundedIcon from '@mui/icons-material/HistoryRounded'
import type { SvgIconComponent } from '@mui/icons-material'
import { PERMISSIONS } from '@/constants/permissions'

export interface NavItem {
  label: string
  to: string
  icon: SvgIconComponent
  permission?: string | string[]
  /** Highlights the parent entry for nested routes such as audit details. */
  match?: string
  /** Short purpose line, shown beside the screen name in the top bar. */
  context?: string
}

export interface NavSection {
  heading?: string
  items: NavItem[]
}

/**
 * The sidebar, laid out in the order the business flow actually runs:
 * master data, then stock, then the count, then what follows from it.
 */
export const navigation: NavSection[] = [
  {
    items: [{ label: 'Dashboard', to: '/', icon: DashboardRoundedIcon, context: 'Where verification stands today' }],
  },
  {
    heading: 'Master',
    items: [
      { label: 'Shops', to: '/shops', icon: StorefrontRoundedIcon, permission: PERMISSIONS.shopsView, context: 'Shop records and user access' },
      { label: 'Items', to: '/items', icon: MedicationRoundedIcon, permission: PERMISSIONS.itemsView, context: 'Pharmacy product master' },
      { label: 'HHT Devices', to: '/devices', icon: TabletAndroidRoundedIcon, permission: PERMISSIONS.devicesView, context: 'Handheld terminals per shop' },
      { label: 'Stock Import', to: '/stock-import', icon: UploadFileRoundedIcon, permission: PERMISSIONS.stockView, context: 'Excel upload that replaces shop stock' },
    ],
  },
  {
    // Verification and Variance were removed from this menu deliberately, not
    // deleted: Verification is still reachable at /verification from the audit
    // detail flow, and Variance's job — reviewing a counted line and posting
    // against it — is now Stock Adjustment below, so a separate menu entry for
    // it would just be the same screen listed twice.
    heading: 'Stock Verification',
    items: [
      { label: 'Item Stock', to: '/item-stock', icon: Inventory2RoundedIcon, permission: PERMISSIONS.stockView, context: 'System stock by shop, batch and expiry' },
      { label: 'HHT Submissions', to: '/hht', icon: PhonelinkRingRoundedIcon, permission: PERMISSIONS.hhtView, context: 'Counts received from handheld devices' },
      { label: 'Stock Audit', to: '/audits', icon: FactCheckRoundedIcon, permission: PERMISSIONS.auditsView, context: 'Scan and count a shop live from the browser, or review what has come in' },
      { label: 'Stock Adjustment', to: '/stock-adjustment', icon: TuneRoundedIcon, permission: PERMISSIONS.varianceView, context: 'Physical count compared with system stock, and post the correction' },
      { label: 'Stock Adj (Audit Log)', to: '/stock-adjustment-log', icon: HistoryEduRoundedIcon, permission: PERMISSIONS.adjustmentsView, context: 'History of adjustments already posted' },
      { label: 'Stock Take', to: '/stock-take', icon: PlaylistAddCheckRoundedIcon, permission: PERMISSIONS.stockTakeView, context: 'Counts recorded outside a device audit' },
    ],
  },
  {
    heading: 'Output',
    items: [
      { label: 'Reports', to: '/reports', icon: AssessmentRoundedIcon, permission: PERMISSIONS.reportsView, context: 'Nine standard reports, Excel and PDF' },
      { label: 'Final Output', to: '/final-output', icon: CloudUploadRoundedIcon, permission: PERMISSIONS.finalOutputView, context: 'Generate the output file and share to OneDrive' },
    ],
  },
  {
    heading: 'Administration',
    items: [
      { label: 'Users', to: '/users', icon: GroupRoundedIcon, permission: PERMISSIONS.usersManage, context: 'Accounts, roles and shop assignments' },
      { label: 'Activity Log', to: '/activity-log', icon: HistoryRoundedIcon, permission: PERMISSIONS.activityView, context: 'Who changed what, and when' },
      { label: 'Settings', to: '/settings', icon: SettingsRoundedIcon, context: 'Application configuration' },
    ],
  },
]

/** What the sticky top bar shows for the current URL. */
export interface ScreenIdentity {
  label: string
  icon: SvgIconComponent
  section: string
  context?: string
}

/** Reachable by URL, but deliberately absent from the sidebar. */
const UNLISTED_SCREENS: Array<ScreenIdentity & { to: string }> = [
  {
    // Kept reachable from the audit detail flow, but removed from the menu:
    // see the comment on the Stock Verification section above.
    to: '/verification',
    label: 'Verification',
    icon: RuleRoundedIcon,
    section: 'Stock Verification',
    context: 'Review counted lines against system stock',
  },
  {
    to: '/hht/simulator',
    label: 'HHT Simulator',
    icon: PhonelinkRingRoundedIcon,
    section: 'Stock Verification',
    context: 'Send a test count as a handheld device',
  },
  {
    to: '/hht/import',
    label: 'Import HHT Export',
    icon: UploadFileRoundedIcon,
    section: 'Stock Verification',
    context: "Read a handheld's Excel export",
  },
]

const UNKNOWN_SCREEN: ScreenIdentity = {
  label: 'Pharmacy Stock Verification',
  icon: Inventory2RoundedIcon,
  section: 'PharmaVerify',
}

/**
 * The screen a URL belongs to.
 *
 * A page's own heading scrolls away on a long table; the top bar does not, so
 * it carries the same identity all the way down. Matching mirrors the sidebar
 * exactly, with the longest path winning — so `/hht/simulator` resolves to the
 * simulator rather than the submissions list it sits under, and `/audits/12`
 * resolves to Stock Audit rather than falling through to the dashboard.
 */
export function resolveScreen(pathname: string): ScreenIdentity {
  const unlisted = UNLISTED_SCREENS.find(
    (screen) => pathname === screen.to || pathname.startsWith(`${screen.to}/`),
  )
  if (unlisted) return unlisted

  let best: ScreenIdentity | null = null
  let bestLength = -1

  for (const section of navigation) {
    for (const item of section.items) {
      const matched =
        item.to === '/'
          ? pathname === '/'
          : pathname === item.to || pathname.startsWith(`${item.to}/`)

      if (matched && item.to.length > bestLength) {
        bestLength = item.to.length
        best = {
          label: item.label,
          icon: item.icon,
          section: section.heading ?? 'Overview',
          context: item.context,
        }
      }
    }
  }

  return best ?? UNKNOWN_SCREEN
}
