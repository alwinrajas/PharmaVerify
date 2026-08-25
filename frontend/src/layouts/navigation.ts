import DashboardRoundedIcon from '@mui/icons-material/DashboardRounded'
import StorefrontRoundedIcon from '@mui/icons-material/StorefrontRounded'
import MedicationRoundedIcon from '@mui/icons-material/MedicationRounded'
import TabletAndroidRoundedIcon from '@mui/icons-material/TabletAndroidRounded'
import UploadFileRoundedIcon from '@mui/icons-material/UploadFileRounded'
import Inventory2RoundedIcon from '@mui/icons-material/Inventory2Rounded'
import PhonelinkRingRoundedIcon from '@mui/icons-material/PhonelinkRingRounded'
import FactCheckRoundedIcon from '@mui/icons-material/FactCheckRounded'
import RuleRoundedIcon from '@mui/icons-material/RuleRounded'
import CompareArrowsRoundedIcon from '@mui/icons-material/CompareArrowsRounded'
import TuneRoundedIcon from '@mui/icons-material/TuneRounded'
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
    items: [{ label: 'Dashboard', to: '/', icon: DashboardRoundedIcon }],
  },
  {
    heading: 'Master',
    items: [
      { label: 'Shops', to: '/shops', icon: StorefrontRoundedIcon, permission: PERMISSIONS.shopsView },
      { label: 'Items', to: '/items', icon: MedicationRoundedIcon, permission: PERMISSIONS.itemsView },
      { label: 'HHT Devices', to: '/devices', icon: TabletAndroidRoundedIcon, permission: PERMISSIONS.devicesView },
      { label: 'Stock Import', to: '/stock-import', icon: UploadFileRoundedIcon, permission: PERMISSIONS.stockView },
    ],
  },
  {
    heading: 'Stock Verification',
    items: [
      { label: 'Item Stock', to: '/item-stock', icon: Inventory2RoundedIcon, permission: PERMISSIONS.stockView },
      { label: 'HHT Submissions', to: '/hht', icon: PhonelinkRingRoundedIcon, permission: PERMISSIONS.hhtView },
      { label: 'Stock Audit', to: '/audits', icon: FactCheckRoundedIcon, permission: PERMISSIONS.auditsView },
      { label: 'Verification', to: '/verification', icon: RuleRoundedIcon, permission: PERMISSIONS.auditsView },
      { label: 'Variance', to: '/variance', icon: CompareArrowsRoundedIcon, permission: PERMISSIONS.varianceView },
      { label: 'Stock Adjustment', to: '/adjustments', icon: TuneRoundedIcon, permission: PERMISSIONS.adjustmentsView },
      { label: 'Stock Take', to: '/stock-take', icon: PlaylistAddCheckRoundedIcon, permission: PERMISSIONS.stockTakeView },
    ],
  },
  {
    heading: 'Output',
    items: [
      { label: 'Reports', to: '/reports', icon: AssessmentRoundedIcon, permission: PERMISSIONS.reportsView },
      { label: 'Final Output', to: '/final-output', icon: CloudUploadRoundedIcon, permission: PERMISSIONS.finalOutputView },
    ],
  },
  {
    heading: 'Administration',
    items: [
      { label: 'Users', to: '/users', icon: GroupRoundedIcon, permission: PERMISSIONS.usersManage },
      { label: 'Activity Log', to: '/activity-log', icon: HistoryRoundedIcon, permission: PERMISSIONS.activityView },
      { label: 'Settings', to: '/settings', icon: SettingsRoundedIcon },
    ],
  },
]
