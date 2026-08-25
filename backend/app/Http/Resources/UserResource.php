<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'employee_code' => $this->employee_code,
            'phone' => $this->phone,
            'status' => $this->status,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')->values(), []),
            'permissions' => $this->when(
                $request->routeIs('auth.*') || $request->boolean('with_permissions'),
                fn () => $this->getAllPermissions()->pluck('name')->values()
            ),
            'shops' => ShopResource::collection($this->whenLoaded('shops')),
        ];
    }
}
