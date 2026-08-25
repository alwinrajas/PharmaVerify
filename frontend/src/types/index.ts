/** Shapes returned by the PharmaVerify API. */

export interface ApiEnvelope<T> {
  success: boolean
  message?: string
  data: T
  meta?: PaginationMeta & Record<string, unknown>
}

export interface PaginationMeta {
  current_page: number
  per_page: number
  total: number
  last_page: number
  from?: number | null
  to?: number | null
}

export interface Shop {
  id: number
  shop_code: string
  shop_name: string
  address: string | null
  city: string | null
  contact_person: string | null
  contact_number: string | null
  status: string
  devices_count?: number
  item_stocks_count?: number
  audits_count?: number
  created_at?: string
  updated_at?: string
}

export interface Item {
  id: number
  product_code: string
  barcode: string | null
  description: string
  generic_name: string | null
  manufacturer: string | null
  uom: string
  price: number
  status: string
  created_at?: string
  updated_at?: string
}

export interface Device {
  id: number
  shop_id: number
  shop_code?: string
  shop_name?: string
  device_code: string
  description: string | null
  serial_number: string | null
  status: string
  last_submission_at: string | null
}

export interface ItemStock {
  id: number
  shop_id: number
  shop_code?: string
  shop_name?: string
  product_code: string
  barcode: string | null
  description: string
  system_qty: number
  uom: string
  price: number
  batch: string
  expiry_date: string | null
  shelf_location: string | null
  verification_status: string
  updated_at?: string
}

export interface StockImportError {
  id: number
  row_number: number
  column_name: string | null
  column_value: string | null
  error_message: string
}

export interface StockImport {
  id: number
  shop_id: number
  shop_code?: string
  shop_name?: string
  file_name: string
  total_records: number
  success_records: number
  failed_records: number
  replaced_records: number
  status: string
  failure_reason: string | null
  imported_by?: string | null
  imported_at: string | null
  errors?: StockImportError[]
}

export interface Audit {
  id: number
  audit_number: number
  shop_id: number
  shop_code?: string
  shop_name?: string
  device_id: number
  device_code?: string
  hht_user: string | null
  audit_date: string | null
  submitted_at: string | null
  item_count: number
  variance_count: number
  status: string
  verified_by?: string | null
  verified_at: string | null
  lines?: AuditLine[]
}

export interface AuditLine {
  id: number
  audit_id: number
  audit_number?: number
  shop_id: number
  shop_code?: string
  device_code?: string
  item_stock_id: number | null
  product_code: string | null
  barcode: string | null
  description: string | null
  system_qty: number
  physical_qty: number
  variance_qty: number
  uom: string
  price: number
  batch: string
  expiry_date: string | null
  shelf_location: string | null
  is_unknown_item: boolean
  verification_status: string
  adjustment_status: string
  verified_by?: string | null
  verified_at: string | null
  adjusted_at: string | null
  remarks: string | null
}

export interface HhtSubmission {
  id: number
  submission_uid: string
  shop_id: number
  shop_code?: string
  shop_name?: string
  device_id: number
  device_code?: string
  audit_number: number
  audit_date: string | null
  hht_user: string | null
  app_version: string | null
  item_count: number
  status: string
  message: string | null
  audit_id: number | null
  received_at: string | null
  audit?: Audit
}

export interface StockAdjustment {
  id: number
  audit_id: number | null
  audit_line_id: number | null
  audit_number?: number
  device_code?: string
  shop_id: number
  shop_code?: string
  shop_name?: string
  product_code: string | null
  barcode: string | null
  description: string | null
  batch: string
  old_system_qty: number
  physical_qty: number
  variance_qty: number
  new_system_qty: number
  reason: string | null
  adjusted_by?: string | null
  adjusted_at: string | null
}

export interface StockTake {
  id: number
  shop_id: number
  shop_code?: string
  shop_name?: string
  audit_id: number | null
  audit_number?: number
  barcode: string | null
  product_code: string | null
  description: string
  physical_qty: number
  uom: string
  batch: string
  expiry_date: string | null
  shelf_location: string | null
  status: string
  remarks: string | null
  taken_by?: string | null
  taken_at: string | null
}

export interface FinalOutput {
  id: number
  shop_id: number
  shop_code?: string
  shop_name?: string
  audit_id: number
  audit_number?: number
  device_code?: string
  file_name: string
  record_count: number
  verification_status: string
  adjustment_status: string
  onedrive_status: string
  onedrive_url: string | null
  upload_attempts: number
  last_error: string | null
  uploaded_at: string | null
  generated_by?: string | null
  generated_at: string | null
}

export interface AuthUser {
  id: number
  name: string
  email: string
  employee_code: string | null
  phone: string | null
  status: string
  last_login_at: string | null
  roles: string[]
  permissions: string[]
  shops?: Shop[]
}

export interface ManagedUser extends Omit<AuthUser, 'permissions'> {
  permissions?: string[]
  created_at?: string
}

export interface SelectOption {
  id: number
  code?: string
  label: string
  shop_id?: number
}

export interface ReportColumn {
  key: string
  label: string
  type: 'text' | 'number' | 'decimal' | 'money' | 'date' | 'datetime'
  width?: number
}

export interface ReportDescriptor {
  key: string
  title: string
  description: string
  columns: ReportColumn[]
  filters: string[]
  default_sort: string
  default_sort_dir: 'asc' | 'desc'
}

export type ReportRow = Record<string, string | number | null>

export interface DashboardSummary {
  cards: {
    total_shops: number
    total_items: number
    stock_records: number
    hht_submissions_today: number
    pending_verification: number
    variance_items: number
    pending_adjustments: number
    completed_audits: number
  }
  variance_breakdown: { positive: number; negative: number; zero: number }
  recent_submissions: HhtSubmission[]
  recent_audits: Audit[]
  recent_adjustments: StockAdjustment[]
}

export interface VarianceSummary {
  total_lines: number
  positive_count: number
  positive_quantity: number
  negative_count: number
  negative_quantity: number
  zero_count: number
  net_variance: number
  pending_adjustment: number
}
