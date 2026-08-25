<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Restricts a query to the shops a user is allowed to see.
 *
 * Administrators and supervisors hold the `shops.view_all` permission and are
 * therefore unrestricted. A Shop User only ever sees data belonging to the
 * shops assigned to them through the `shop_user` pivot.
 */
trait ScopesToUserShops
{
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user || ! $user->isShopRestricted()) {
            return $query;
        }

        return $query->whereIn($this->getTable().'.shop_id', $user->assignedShopIds());
    }

    public function scopeForShop(Builder $query, int|string|null $shopId): Builder
    {
        if ($shopId === null || $shopId === '') {
            return $query;
        }

        return $query->where($this->getTable().'.shop_id', $shopId);
    }
}
