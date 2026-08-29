<?php

namespace App\Services\Reports;

use App\Exceptions\BusinessRuleException;
use App\Models\Audit;
use App\Models\AuditLine;
use App\Models\ItemStock;
use App\Models\StockAdjustment;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

/**
 * The nine reports the business asked for, each described once.
 */
class ReportRegistry
{
    /** @var array<string, ReportDefinition>|null */
    private ?array $definitions = null;

    /**
     * @return array<string, ReportDefinition>
     */
    public function all(): array
    {
        return $this->definitions ??= collect([
            $this->varianceReport(),
            $this->auditNumberReport(),
            $this->shopStockReport(),
            $this->overallStockReport(),
            $this->userLogReport(),
            $this->adjustmentReport(),
            $this->detailedReport(),
            $this->varianceSummaryReport(),
            $this->stockOccurrenceReport(),
        ])->keyBy(fn (ReportDefinition $definition) => $definition->key)->all();
    }

    public function find(string $key): ReportDefinition
    {
        $definition = $this->all()[$key] ?? null;

        if (! $definition) {
            throw new BusinessRuleException('The requested report does not exist.', 404);
        }

        return $definition;
    }

    // ---------------------------------------------------------------------
    // 1. Variance Report
    // ---------------------------------------------------------------------
    private function varianceReport(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'variance',
            title: 'Variance Report',
            description: 'Every counted line where the physical quantity differs from the system quantity.',
            permission: Permissions::REPORTS_VIEW,
            columns: [
                ['key' => 'shop_code', 'label' => 'Shop', 'type' => 'text'],
                ['key' => 'audit_number', 'label' => 'Audit No.', 'type' => 'number'],
                ['key' => 'audit_ref', 'label' => 'Audit Ref', 'type' => 'text'],
                ['key' => 'device_code', 'label' => 'Device', 'type' => 'text'],
                ['key' => 'product_code', 'label' => 'Product Code', 'type' => 'text'],
                ['key' => 'barcode', 'label' => 'Barcode', 'type' => 'text'],
                ['key' => 'description', 'label' => 'Product Description', 'type' => 'text', 'width' => 40],
                ['key' => 'batch', 'label' => 'Batch', 'type' => 'text'],
                ['key' => 'expiry_date', 'label' => 'Expiry', 'type' => 'date'],
                ['key' => 'uom', 'label' => 'UOM', 'type' => 'text'],
                ['key' => 'system_qty', 'label' => 'System Qty', 'type' => 'decimal'],
                ['key' => 'source_system_qty', 'label' => 'HHT System Qty', 'type' => 'decimal'],
                ['key' => 'physical_qty', 'label' => 'Physical Qty', 'type' => 'decimal'],
                ['key' => 'loose_qty', 'label' => 'Loose Qty', 'type' => 'decimal'],
                ['key' => 'variance_qty', 'label' => 'Variance', 'type' => 'decimal'],
                ['key' => 'adjustment_status', 'label' => 'Adjustment', 'type' => 'text'],
            ],
            filters: ['shop_id', 'audit_id', 'audit_number', 'device_id', 'variance', 'date_from', 'date_to', 'search'],
            query: fn (Request $request) => $this->auditLineQuery($request)->where('variance_qty', '!=', 0),
            summary: fn (Builder $query) => [
                'lines' => (clone $query)->count(),
                'net_variance' => (float) (clone $query)->sum('variance_qty'),
                // Variance is System - (Physical + Loose): short is positive.
                'short_lines' => (clone $query)->where('variance_qty', '>', 0)->count(),
                'excess_lines' => (clone $query)->where('variance_qty', '<', 0)->count(),
            ],
            defaultSort: 'variance_qty',
            // Descending puts the largest shortage first, which is what the
            // reader of a variance report is looking for.
            defaultSortDir: 'desc',
        );
    }

    // ---------------------------------------------------------------------
    // 2. Audit No. Based Report
    // ---------------------------------------------------------------------
    private function auditNumberReport(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'audit-number',
            title: 'Audit No. Based Report',
            description: 'Verification detail for an audit number, always shown with its shop and device because the number repeats across devices.',
            permission: Permissions::REPORTS_VIEW,
            columns: [
                ['key' => 'shop_code', 'label' => 'Shop', 'type' => 'text'],
                ['key' => 'device_code', 'label' => 'Device', 'type' => 'text'],
                ['key' => 'audit_number', 'label' => 'Audit No.', 'type' => 'number'],
                ['key' => 'audit_ref', 'label' => 'Audit Ref', 'type' => 'text'],
                ['key' => 'product_code', 'label' => 'Product Code', 'type' => 'text'],
                ['key' => 'description', 'label' => 'Product Description', 'type' => 'text', 'width' => 40],
                ['key' => 'batch', 'label' => 'Batch', 'type' => 'text'],
                ['key' => 'system_qty', 'label' => 'System Qty', 'type' => 'decimal'],
                ['key' => 'source_system_qty', 'label' => 'HHT System Qty', 'type' => 'decimal'],
                ['key' => 'physical_qty', 'label' => 'Physical Qty', 'type' => 'decimal'],
                ['key' => 'loose_qty', 'label' => 'Loose Qty', 'type' => 'decimal'],
                ['key' => 'variance_qty', 'label' => 'Variance', 'type' => 'decimal'],
                ['key' => 'verification_status', 'label' => 'Verification', 'type' => 'text'],
                ['key' => 'adjustment_status', 'label' => 'Adjustment', 'type' => 'text'],
            ],
            filters: ['shop_id', 'device_id', 'audit_number', 'audit_id', 'variance', 'search'],
            query: fn (Request $request) => $this->auditLineQuery($request),
            summary: fn (Builder $query) => [
                'lines' => (clone $query)->count(),
                'with_variance' => (clone $query)->where('variance_qty', '!=', 0)->count(),
                'net_variance' => (float) (clone $query)->sum('variance_qty'),
            ],
            defaultSort: 'product_code',
            defaultSortDir: 'asc',
        );
    }

    // ---------------------------------------------------------------------
    // 3. Individual Shop Based Report
    // ---------------------------------------------------------------------
    private function shopStockReport(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'shop-stock',
            title: 'Individual Shop Based Report',
            description: 'Current system stock held by one selected shop.',
            permission: Permissions::REPORTS_VIEW,
            columns: [
                ['key' => 'shop_code', 'label' => 'Shop', 'type' => 'text'],
                ['key' => 'product_code', 'label' => 'Product Code', 'type' => 'text'],
                ['key' => 'barcode', 'label' => 'Barcode', 'type' => 'text'],
                ['key' => 'description', 'label' => 'Product Description', 'type' => 'text', 'width' => 40],
                ['key' => 'batch', 'label' => 'Batch', 'type' => 'text'],
                ['key' => 'expiry_date', 'label' => 'Expiry', 'type' => 'date'],
                ['key' => 'uom', 'label' => 'UOM', 'type' => 'text'],
                ['key' => 'system_qty', 'label' => 'System Qty', 'type' => 'decimal'],
                ['key' => 'source_system_qty', 'label' => 'HHT System Qty', 'type' => 'decimal'],
                ['key' => 'price', 'label' => 'Price', 'type' => 'money'],
                ['key' => 'stock_value', 'label' => 'Stock Value', 'type' => 'money'],
                ['key' => 'shelf_location', 'label' => 'Shelf', 'type' => 'text'],
            ],
            filters: ['shop_id', 'verification_status', 'expiry_from', 'expiry_to', 'search'],
            query: fn (Request $request) => $this->itemStockQuery($request),
            summary: fn (Builder $query) => [
                'records' => (clone $query)->count(),
                'total_quantity' => (float) (clone $query)->sum('system_qty'),
            ],
            defaultSort: 'product_code',
            defaultSortDir: 'asc',
        );
    }

    // ---------------------------------------------------------------------
    // 4. Overall Stock Report
    // ---------------------------------------------------------------------
    private function overallStockReport(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'overall-stock',
            title: 'Overall Stock Report',
            description: 'Consolidated system stock across every shop.',
            permission: Permissions::REPORTS_VIEW,
            columns: [
                ['key' => 'shop_code', 'label' => 'Shop', 'type' => 'text'],
                ['key' => 'shop_name', 'label' => 'Shop Name', 'type' => 'text', 'width' => 32],
                ['key' => 'product_code', 'label' => 'Product Code', 'type' => 'text'],
                ['key' => 'description', 'label' => 'Product Description', 'type' => 'text', 'width' => 40],
                ['key' => 'batch', 'label' => 'Batch', 'type' => 'text'],
                ['key' => 'expiry_date', 'label' => 'Expiry', 'type' => 'date'],
                ['key' => 'system_qty', 'label' => 'System Qty', 'type' => 'decimal'],
                ['key' => 'source_system_qty', 'label' => 'HHT System Qty', 'type' => 'decimal'],
                ['key' => 'price', 'label' => 'Price', 'type' => 'money'],
                ['key' => 'stock_value', 'label' => 'Stock Value', 'type' => 'money'],
            ],
            filters: ['shop_id', 'expiry_from', 'expiry_to', 'search'],
            query: fn (Request $request) => $this->itemStockQuery($request),
            summary: fn (Builder $query) => [
                'records' => (clone $query)->count(),
                'total_quantity' => (float) (clone $query)->sum('system_qty'),
            ],
            defaultSort: 'shop_id',
            defaultSortDir: 'asc',
        );
    }

    // ---------------------------------------------------------------------
    // 5. User Log Report
    // ---------------------------------------------------------------------
    private function userLogReport(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'user-log',
            title: 'User Log Report',
            description: 'User activity and the significant actions recorded by the system.',
            permission: Permissions::REPORTS_VIEW,
            columns: [
                ['key' => 'logged_at', 'label' => 'Date / Time', 'type' => 'datetime'],
                ['key' => 'user_name', 'label' => 'User', 'type' => 'text'],
                ['key' => 'log_name', 'label' => 'Module', 'type' => 'text'],
                ['key' => 'description', 'label' => 'Action', 'type' => 'text', 'width' => 45],
                ['key' => 'subject', 'label' => 'Record', 'type' => 'text'],
                ['key' => 'detail', 'label' => 'Detail', 'type' => 'text', 'width' => 50],
            ],
            filters: ['user_id', 'log_name', 'date_from', 'date_to', 'search'],
            query: fn (Request $request) => $this->activityQuery($request),
            defaultSort: 'created_at',
            defaultSortDir: 'desc',
        );
    }

    // ---------------------------------------------------------------------
    // 6. Stock Adjustment Report
    // ---------------------------------------------------------------------
    private function adjustmentReport(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'adjustment',
            title: 'Stock Adjustment Report',
            description: 'Adjustments already posted, with the quantity before and after the correction.',
            permission: Permissions::REPORTS_VIEW,
            columns: [
                ['key' => 'adjusted_at', 'label' => 'Adjusted On', 'type' => 'datetime'],
                ['key' => 'shop_code', 'label' => 'Shop', 'type' => 'text'],
                ['key' => 'audit_number', 'label' => 'Audit No.', 'type' => 'number'],
                ['key' => 'audit_ref', 'label' => 'Audit Ref', 'type' => 'text'],
                ['key' => 'device_code', 'label' => 'Device', 'type' => 'text'],
                ['key' => 'product_code', 'label' => 'Product Code', 'type' => 'text'],
                ['key' => 'description', 'label' => 'Product Description', 'type' => 'text', 'width' => 38],
                ['key' => 'batch', 'label' => 'Batch', 'type' => 'text'],
                ['key' => 'old_system_qty', 'label' => 'Previous Qty', 'type' => 'decimal'],
                ['key' => 'physical_qty', 'label' => 'Physical Qty', 'type' => 'decimal'],
                ['key' => 'loose_qty', 'label' => 'Loose Qty', 'type' => 'decimal'],
                ['key' => 'variance_qty', 'label' => 'Variance', 'type' => 'decimal'],
                ['key' => 'new_system_qty', 'label' => 'New Qty', 'type' => 'decimal'],
                ['key' => 'adjusted_by', 'label' => 'Adjusted By', 'type' => 'text'],
                ['key' => 'reason', 'label' => 'Reason', 'type' => 'text', 'width' => 30],
            ],
            filters: ['shop_id', 'audit_id', 'adjusted_by', 'date_from', 'date_to', 'search'],
            query: fn (Request $request) => $this->adjustmentQuery($request),
            summary: fn (Builder $query) => [
                'adjustments' => (clone $query)->count(),
                'net_variance' => (float) (clone $query)->sum('variance_qty'),
            ],
            defaultSort: 'adjusted_at',
            defaultSortDir: 'desc',
        );
    }

    // ---------------------------------------------------------------------
    // 7. Detailed Report
    // ---------------------------------------------------------------------
    private function detailedReport(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'detailed',
            title: 'Detailed Report',
            description: 'Every counted line with its full stock, audit and verification context.',
            permission: Permissions::REPORTS_VIEW,
            columns: [
                ['key' => 'shop_code', 'label' => 'Shop', 'type' => 'text'],
                ['key' => 'device_code', 'label' => 'Device', 'type' => 'text'],
                ['key' => 'audit_number', 'label' => 'Audit No.', 'type' => 'number'],
                ['key' => 'audit_ref', 'label' => 'Audit Ref', 'type' => 'text'],
                ['key' => 'audit_date', 'label' => 'Audit Date', 'type' => 'date'],
                ['key' => 'product_code', 'label' => 'Product Code', 'type' => 'text'],
                ['key' => 'barcode', 'label' => 'Barcode', 'type' => 'text'],
                ['key' => 'description', 'label' => 'Product Description', 'type' => 'text', 'width' => 38],
                ['key' => 'batch', 'label' => 'Batch', 'type' => 'text'],
                ['key' => 'expiry_date', 'label' => 'Expiry', 'type' => 'date'],
                ['key' => 'shelf_location', 'label' => 'Shelf', 'type' => 'text'],
                ['key' => 'uom', 'label' => 'UOM', 'type' => 'text'],
                ['key' => 'price', 'label' => 'Price', 'type' => 'money'],
                ['key' => 'system_qty', 'label' => 'System Qty', 'type' => 'decimal'],
                ['key' => 'source_system_qty', 'label' => 'HHT System Qty', 'type' => 'decimal'],
                ['key' => 'physical_qty', 'label' => 'Physical Qty', 'type' => 'decimal'],
                ['key' => 'loose_qty', 'label' => 'Loose Qty', 'type' => 'decimal'],
                ['key' => 'variance_qty', 'label' => 'Variance', 'type' => 'decimal'],
                ['key' => 'verification_status', 'label' => 'Verification', 'type' => 'text'],
                ['key' => 'adjustment_status', 'label' => 'Adjustment', 'type' => 'text'],
            ],
            filters: ['shop_id', 'device_id', 'audit_id', 'audit_number', 'variance', 'date_from', 'date_to', 'search'],
            query: fn (Request $request) => $this->auditLineQuery($request),
            defaultSort: 'id',
            defaultSortDir: 'asc',
        );
    }

    // ---------------------------------------------------------------------
    // 8. Variance Summary Report
    // ---------------------------------------------------------------------
    private function varianceSummaryReport(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'variance-summary',
            title: 'Variance Summary Report',
            description: 'Variance totalled per audit, so a count can be judged at a glance.',
            permission: Permissions::REPORTS_VIEW,
            columns: [
                ['key' => 'shop_code', 'label' => 'Shop', 'type' => 'text'],
                ['key' => 'device_code', 'label' => 'Device', 'type' => 'text'],
                ['key' => 'audit_number', 'label' => 'Audit No.', 'type' => 'number'],
                ['key' => 'audit_ref', 'label' => 'Audit Ref', 'type' => 'text'],
                ['key' => 'audit_date', 'label' => 'Audit Date', 'type' => 'date'],
                ['key' => 'item_count', 'label' => 'Items Counted', 'type' => 'number'],
                ['key' => 'short_lines', 'label' => 'Short Lines', 'type' => 'number'],
                ['key' => 'excess_lines', 'label' => 'Excess Lines', 'type' => 'number'],
                ['key' => 'matched_lines', 'label' => 'Matched Lines', 'type' => 'number'],
                ['key' => 'loose_total', 'label' => 'Loose Qty', 'type' => 'decimal'],
                ['key' => 'net_variance', 'label' => 'Net Variance', 'type' => 'decimal'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
            ],
            filters: ['shop_id', 'device_id', 'audit_number', 'date_from', 'date_to'],
            query: fn (Request $request) => $this->auditSummaryQuery($request),
            defaultSort: 'audit_date',
            defaultSortDir: 'desc',
        );
    }

    // ---------------------------------------------------------------------
    // 9. Stock Occurrence Report
    // ---------------------------------------------------------------------
    private function stockOccurrenceReport(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'stock-occurrence',
            title: 'Stock Occurrence Report',
            description: 'How often a product has been counted, and where, across shops and audits.',
            permission: Permissions::REPORTS_VIEW,
            columns: [
                ['key' => 'product_code', 'label' => 'Product Code', 'type' => 'text'],
                ['key' => 'description', 'label' => 'Product Description', 'type' => 'text', 'width' => 42],
                ['key' => 'shop_count', 'label' => 'Shops', 'type' => 'number'],
                ['key' => 'audit_count', 'label' => 'Audits', 'type' => 'number'],
                ['key' => 'occurrences', 'label' => 'Occurrences', 'type' => 'number'],
                ['key' => 'total_system_qty', 'label' => 'System Qty', 'type' => 'decimal'],
                ['key' => 'total_physical_qty', 'label' => 'Physical Qty', 'type' => 'decimal'],
                ['key' => 'net_variance', 'label' => 'Net Variance', 'type' => 'decimal'],
            ],
            filters: ['shop_id', 'date_from', 'date_to', 'search'],
            query: fn (Request $request) => $this->occurrenceQuery($request),
            defaultSort: 'occurrences',
            defaultSortDir: 'desc',
        );
    }

    // ---------------------------------------------------------------------
    // Shared query builders
    // ---------------------------------------------------------------------

    private function auditLineQuery(Request $request): Builder
    {
        $query = AuditLine::query()
            ->visibleTo($request->user())
            ->join('audits', 'audits.id', '=', 'audit_lines.audit_id')
            ->join('shops', 'shops.id', '=', 'audit_lines.shop_id')
            ->join('devices', 'devices.id', '=', 'audits.device_id')
            ->select([
                'audit_lines.*',
                'shops.shop_code',
                'shops.shop_name',
                'devices.device_code',
                'audits.audit_number',
                'audits.audit_ref',
                'audits.audit_date',
            ]);

        $this->applyCommonFilters($query, $request, [
            'shop_id' => 'audit_lines.shop_id',
            'audit_id' => 'audit_lines.audit_id',
            'audit_number' => 'audits.audit_number',
            'device_id' => 'audits.device_id',
            'verification_status' => 'audit_lines.verification_status',
            'adjustment_status' => 'audit_lines.adjustment_status',
        ]);

        $this->applySearch($query, $request, [
            'audit_lines.product_code',
            'audit_lines.barcode',
            'audit_lines.description',
            'audit_lines.batch',
        ]);

        $this->applyDateRange($query, $request, 'audits.audit_date');

        if ($direction = $request->query('variance')) {
            $query->varianceDirection($direction === 'all' ? null : $direction);
        }

        return $query;
    }

    private function itemStockQuery(Request $request): Builder
    {
        $query = ItemStock::query()
            ->visibleTo($request->user())
            ->join('shops', 'shops.id', '=', 'item_stocks.shop_id')
            ->select([
                'item_stocks.*',
                'shops.shop_code',
                'shops.shop_name',
            ]);

        $this->applyCommonFilters($query, $request, [
            'shop_id' => 'item_stocks.shop_id',
            'verification_status' => 'item_stocks.verification_status',
        ]);

        $this->applySearch($query, $request, [
            'item_stocks.product_code',
            'item_stocks.barcode',
            'item_stocks.description',
            'item_stocks.batch',
        ]);

        if ($from = $request->query('expiry_from')) {
            $query->whereDate('item_stocks.expiry_date', '>=', $from);
        }

        if ($to = $request->query('expiry_to')) {
            $query->whereDate('item_stocks.expiry_date', '<=', $to);
        }

        return $query;
    }

    private function adjustmentQuery(Request $request): Builder
    {
        $query = StockAdjustment::query()
            ->visibleTo($request->user())
            ->join('shops', 'shops.id', '=', 'stock_adjustments.shop_id')
            ->leftJoin('audits', 'audits.id', '=', 'stock_adjustments.audit_id')
            ->leftJoin('devices', 'devices.id', '=', 'audits.device_id')
            ->leftJoin('users', 'users.id', '=', 'stock_adjustments.adjusted_by')
            ->select([
                'stock_adjustments.*',
                'shops.shop_code',
                'shops.shop_name',
                'audits.audit_number',
                'devices.device_code',
                'users.name as adjusted_by_name',
            ]);

        $this->applyCommonFilters($query, $request, [
            'shop_id' => 'stock_adjustments.shop_id',
            'audit_id' => 'stock_adjustments.audit_id',
            'adjusted_by' => 'stock_adjustments.adjusted_by',
        ]);

        $this->applySearch($query, $request, [
            'stock_adjustments.product_code',
            'stock_adjustments.barcode',
            'stock_adjustments.description',
            'stock_adjustments.batch',
        ]);

        $this->applyDateRange($query, $request, 'stock_adjustments.adjusted_at');

        return $query;
    }

    private function auditSummaryQuery(Request $request): Builder
    {
        $query = Audit::query()
            ->visibleTo($request->user())
            ->join('shops', 'shops.id', '=', 'audits.shop_id')
            ->join('devices', 'devices.id', '=', 'audits.device_id')
            ->leftJoin('audit_lines', 'audit_lines.audit_id', '=', 'audits.id')
            ->groupBy('audits.id', 'audits.audit_number', 'audits.audit_date', 'audits.item_count', 'audits.status', 'shops.shop_code', 'devices.device_code')
            ->select([
                'audits.id',
                'audits.audit_number',
                'audits.audit_date',
                'audits.item_count',
                'audits.status',
                'shops.shop_code',
                'devices.device_code',
            ])
            // Variance is System - (Physical + Loose), so a positive figure is
            // a shortage. Getting these two the wrong way round would report a
            // shortage as a surplus without raising an error anywhere.
            ->selectRaw('SUM(CASE WHEN audit_lines.variance_qty > 0 THEN 1 ELSE 0 END) as short_lines')
            ->selectRaw('SUM(CASE WHEN audit_lines.variance_qty < 0 THEN 1 ELSE 0 END) as excess_lines')
            ->selectRaw('SUM(CASE WHEN audit_lines.variance_qty = 0 THEN 1 ELSE 0 END) as matched_lines')
            ->selectRaw('COALESCE(SUM(audit_lines.variance_qty), 0) as net_variance')
            ->selectRaw('COALESCE(SUM(audit_lines.loose_qty), 0) as loose_total');

        $this->applyCommonFilters($query, $request, [
            'shop_id' => 'audits.shop_id',
            'device_id' => 'audits.device_id',
            'audit_number' => 'audits.audit_number',
            'status' => 'audits.status',
        ]);

        $this->applyDateRange($query, $request, 'audits.audit_date');

        return $query;
    }

    private function occurrenceQuery(Request $request): Builder
    {
        $query = AuditLine::query()
            ->visibleTo($request->user())
            ->join('audits', 'audits.id', '=', 'audit_lines.audit_id')
            ->groupBy('audit_lines.product_code', 'audit_lines.description')
            ->select([
                'audit_lines.product_code',
                'audit_lines.description',
            ])
            ->selectRaw('COUNT(*) as occurrences')
            ->selectRaw('COUNT(DISTINCT audit_lines.shop_id) as shop_count')
            ->selectRaw('COUNT(DISTINCT audit_lines.audit_id) as audit_count')
            ->selectRaw('COALESCE(SUM(audit_lines.system_qty), 0) as total_system_qty')
            ->selectRaw('COALESCE(SUM(audit_lines.physical_qty), 0) as total_physical_qty')
            ->selectRaw('COALESCE(SUM(audit_lines.variance_qty), 0) as net_variance');

        $this->applyCommonFilters($query, $request, ['shop_id' => 'audit_lines.shop_id']);
        $this->applySearch($query, $request, ['audit_lines.product_code', 'audit_lines.description']);
        $this->applyDateRange($query, $request, 'audits.audit_date');

        return $query;
    }

    private function activityQuery(Request $request): Builder
    {
        $query = Activity::query()
            ->with('causer')
            ->leftJoin('users', 'users.id', '=', 'activity_log.causer_id')
            ->select(['activity_log.*', 'users.name as user_name']);

        if ($userId = $request->query('user_id')) {
            $query->where('activity_log.causer_id', $userId);
        }

        if ($logName = $request->query('log_name')) {
            $query->where('activity_log.log_name', $logName);
        }

        $this->applySearch($query, $request, ['activity_log.description', 'users.name']);
        $this->applyDateRange($query, $request, 'activity_log.created_at');

        return $query;
    }

    /**
     * @param  array<string, string>  $map
     */
    private function applyCommonFilters(Builder $query, Request $request, array $map): void
    {
        foreach ($map as $key => $column) {
            $value = $request->query($key);

            if ($value !== null && $value !== '') {
                $query->where($column, $value);
            }
        }
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function applySearch(Builder $query, Request $request, array $columns): void
    {
        $term = trim((string) $request->query('search', ''));

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $inner) use ($columns, $term) {
            foreach ($columns as $column) {
                $inner->orWhere($column, 'like', '%'.$term.'%');
            }
        });
    }

    private function applyDateRange(Builder $query, Request $request, string $column): void
    {
        if ($from = $request->query('date_from')) {
            $query->whereDate($column, '>=', $from);
        }

        if ($to = $request->query('date_to')) {
            $query->whereDate($column, '<=', $to);
        }
    }
}
