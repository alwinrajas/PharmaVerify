<?php

namespace App\Support;

/**
 * The single source of truth for the application's permission set.
 *
 * Authorisation is permission based. Roles are simply named collections of
 * these permissions, so a role's reach can be changed without touching code.
 */
final class Permissions
{
    // Master data
    public const SHOPS_VIEW = 'shops.view';
    public const SHOPS_VIEW_ALL = 'shops.view_all';
    public const SHOPS_CREATE = 'shops.create';
    public const SHOPS_EDIT = 'shops.edit';
    public const SHOPS_DELETE = 'shops.delete';

    public const ITEMS_VIEW = 'items.view';
    public const ITEMS_CREATE = 'items.create';
    public const ITEMS_EDIT = 'items.edit';
    public const ITEMS_DELETE = 'items.delete';

    public const DEVICES_VIEW = 'devices.view';
    public const DEVICES_CREATE = 'devices.create';
    public const DEVICES_EDIT = 'devices.edit';
    public const DEVICES_DELETE = 'devices.delete';

    // Stock
    public const STOCK_VIEW = 'stock.view';
    public const STOCK_IMPORT = 'stock.import';

    /** Reading a handheld's Excel export into an audit or a stock take. */
    public const HHT_IMPORT = 'hht.import';

    // Verification flow
    public const HHT_VIEW = 'hht.view';
    public const HHT_SUBMIT = 'hht.submit';
    public const AUDITS_VIEW = 'audits.view';
    public const VERIFICATION_EDIT = 'verification.edit';
    public const VARIANCE_VIEW = 'variance.view';
    public const ADJUSTMENTS_VIEW = 'adjustments.view';
    public const ADJUSTMENTS_CREATE = 'adjustments.create';
    public const STOCKTAKE_VIEW = 'stocktake.view';
    public const STOCKTAKE_CREATE = 'stocktake.create';

    // Output
    public const REPORTS_VIEW = 'reports.view';
    public const REPORTS_EXPORT = 'reports.export';
    public const FINALOUTPUT_VIEW = 'finaloutput.view';
    public const FINALOUTPUT_GENERATE = 'finaloutput.generate';
    public const ONEDRIVE_SHARE = 'onedrive.share';

    // Administration
    public const USERS_MANAGE = 'users.manage';
    public const SETTINGS_MANAGE = 'settings.manage';
    public const ACTIVITY_VIEW = 'activity.view';

    /**
     * Every permission, grouped by the module it belongs to. The grouping is
     * used by the user-management screen to render the permission matrix.
     *
     * @return array<string, array<int, string>>
     */
    public static function grouped(): array
    {
        return [
            'Shops' => [self::SHOPS_VIEW, self::SHOPS_VIEW_ALL, self::SHOPS_CREATE, self::SHOPS_EDIT, self::SHOPS_DELETE],
            'Items' => [self::ITEMS_VIEW, self::ITEMS_CREATE, self::ITEMS_EDIT, self::ITEMS_DELETE],
            'Devices' => [self::DEVICES_VIEW, self::DEVICES_CREATE, self::DEVICES_EDIT, self::DEVICES_DELETE],
            'Stock' => [self::STOCK_VIEW, self::STOCK_IMPORT],
            'HHT' => [self::HHT_VIEW, self::HHT_SUBMIT, self::HHT_IMPORT],
            'Audit' => [self::AUDITS_VIEW],
            'Verification' => [self::VERIFICATION_EDIT],
            'Variance' => [self::VARIANCE_VIEW],
            'Adjustment' => [self::ADJUSTMENTS_VIEW, self::ADJUSTMENTS_CREATE],
            'Stock Take' => [self::STOCKTAKE_VIEW, self::STOCKTAKE_CREATE],
            'Reports' => [self::REPORTS_VIEW, self::REPORTS_EXPORT],
            'Final Output' => [self::FINALOUTPUT_VIEW, self::FINALOUTPUT_GENERATE, self::ONEDRIVE_SHARE],
            'Administration' => [self::USERS_MANAGE, self::SETTINGS_MANAGE, self::ACTIVITY_VIEW],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_values(array_merge(...array_values(self::grouped())));
    }

    /**
     * The permissions granted to each seeded role.
     *
     * @return array<string, array<int, string>>
     */
    public static function roleMatrix(): array
    {
        $administrator = self::all();

        $supervisor = array_values(array_diff($administrator, [
            self::SHOPS_DELETE,
            self::ITEMS_DELETE,
            self::DEVICES_DELETE,
            self::USERS_MANAGE,
            self::SETTINGS_MANAGE,
        ]));

        $shopUser = [
            self::SHOPS_VIEW,
            self::ITEMS_VIEW,
            self::DEVICES_VIEW,
            self::STOCK_VIEW,
            self::HHT_VIEW,
            self::AUDITS_VIEW,
            self::VARIANCE_VIEW,
            self::ADJUSTMENTS_VIEW,
            self::STOCKTAKE_VIEW,
            self::STOCKTAKE_CREATE,
            self::REPORTS_VIEW,
            self::REPORTS_EXPORT,
            self::FINALOUTPUT_VIEW,
        ];

        return [
            Roles::ADMINISTRATOR => $administrator,
            Roles::SUPERVISOR => $supervisor,
            Roles::SHOP_USER => $shopUser,
        ];
    }
}
