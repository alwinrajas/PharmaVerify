/**
 * Permission names, mirroring `App\Support\Permissions` on the server.
 *
 * These decide what the interface offers. They never decide what is allowed —
 * every request is checked again on the backend.
 */
export const PERMISSIONS = {
  shopsView: 'shops.view',
  shopsViewAll: 'shops.view_all',
  shopsCreate: 'shops.create',
  shopsEdit: 'shops.edit',
  shopsDelete: 'shops.delete',

  itemsView: 'items.view',
  itemsCreate: 'items.create',
  itemsEdit: 'items.edit',
  itemsDelete: 'items.delete',

  devicesView: 'devices.view',
  devicesCreate: 'devices.create',
  devicesEdit: 'devices.edit',
  devicesDelete: 'devices.delete',

  stockView: 'stock.view',
  stockImport: 'stock.import',

  hhtView: 'hht.view',
  hhtSubmit: 'hht.submit',

  auditsView: 'audits.view',
  verificationEdit: 'verification.edit',
  varianceView: 'variance.view',

  adjustmentsView: 'adjustments.view',
  adjustmentsCreate: 'adjustments.create',

  stockTakeView: 'stocktake.view',
  stockTakeCreate: 'stocktake.create',

  reportsView: 'reports.view',
  reportsExport: 'reports.export',

  finalOutputView: 'finaloutput.view',
  finalOutputGenerate: 'finaloutput.generate',
  oneDriveShare: 'onedrive.share',

  usersManage: 'users.manage',
  settingsManage: 'settings.manage',
  activityView: 'activity.view',
} as const

export type PermissionName = (typeof PERMISSIONS)[keyof typeof PERMISSIONS]
