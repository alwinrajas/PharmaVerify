<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesIndexQueries;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Permissions;
use App\Support\Roles as AppRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    use HandlesIndexQueries;

    public function index(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::USERS_MANAGE) || abort(403);

        $query = User::query()->with(['roles', 'shops']);

        $this->applySearch($query, $request, ['name', 'email', 'employee_code', 'phone']);
        $this->applyEquals($query, $request, ['status' => 'status']);

        if ($role = $request->query('role')) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $role));
        }

        $this->applySort($query, $request, ['name', 'email', 'status', 'last_login_at', 'created_at', 'id'], 'name', 'asc');

        $paginator = $this->paginate($query, $request);

        return ApiResponse::success(
            UserResource::collection($paginator->items()),
            null,
            $this->paginationMeta($paginator)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::USERS_MANAGE) || abort(403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)],
            'employee_code' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'role' => ['required', 'string', 'exists:roles,name'],
            'shop_ids' => ['nullable', 'array'],
            'shop_ids.*' => ['integer', 'exists:shops,id'],
        ], [
            'email.unique' => 'A user with this email address already exists.',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'employee_code' => $validated['employee_code'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'status' => $validated['status'],
        ]);

        $user->syncRoles([$validated['role']]);
        $user->shops()->sync($validated['shop_ids'] ?? []);

        return ApiResponse::success(
            new UserResource($user->load(['roles', 'shops'])),
            'User created successfully.',
            [],
            201
        );
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $request->user()->can(Permissions::USERS_MANAGE) || abort(403);

        return ApiResponse::success(new UserResource($user->load(['roles', 'shops'])));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $request->user()->can(Permissions::USERS_MANAGE) || abort(403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'employee_code' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'role' => ['required', 'string', 'exists:roles,name'],
            'shop_ids' => ['nullable', 'array'],
            'shop_ids.*' => ['integer', 'exists:shops,id'],
        ]);

        // The last administrator must keep their access, otherwise the system
        // could be locked out entirely.
        $this->assertNotLastAdministrator($user, $validated['role'], $validated['status']);

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'employee_code' => $validated['employee_code'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'status' => $validated['status'],
        ]);

        $user->syncRoles([$validated['role']]);
        $user->shops()->sync($validated['shop_ids'] ?? []);

        return ApiResponse::success(
            new UserResource($user->fresh(['roles', 'shops'])),
            'User updated successfully.'
        );
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $request->user()->can(Permissions::USERS_MANAGE) || abort(403);

        $validated = $request->validate([
            'password' => ['required', 'string', Password::min(8)],
        ]);

        $user->update(['password' => $validated['password']]);
        $user->tokens()->delete();

        activity('user')
            ->causedBy($request->user())
            ->performedOn($user)
            ->log('Password reset by administrator');

        return ApiResponse::success(null, 'Password reset successfully. The user must sign in again.');
    }

    public function toggleStatus(Request $request, User $user): JsonResponse
    {
        $request->user()->can(Permissions::USERS_MANAGE) || abort(403);

        $newStatus = $user->status === 'active' ? 'inactive' : 'active';

        $this->assertNotLastAdministrator($user, $user->roles->first()?->name ?? '', $newStatus);

        $user->update(['status' => $newStatus]);

        if ($newStatus === 'inactive') {
            $user->tokens()->delete();
        }

        return ApiResponse::success(
            new UserResource($user->fresh(['roles', 'shops'])),
            $newStatus === 'active' ? 'User activated successfully.' : 'User deactivated successfully.'
        );
    }

    /** Roles and the permissions behind them, for the user form. */
    public function roles(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::USERS_MANAGE) || abort(403);

        return ApiResponse::success([
            'roles' => Role::with('permissions')->get()->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->values(),
            ]),
            'permission_groups' => Permissions::grouped(),
        ]);
    }

    private function assertNotLastAdministrator(User $user, string $newRole, string $newStatus): void
    {
        if (! $user->hasRole(AppRoles::ADMINISTRATOR)) {
            return;
        }

        $stillAdministrator = $newRole === AppRoles::ADMINISTRATOR && $newStatus === 'active';

        if ($stillAdministrator) {
            return;
        }

        $remaining = User::role(AppRoles::ADMINISTRATOR)
            ->where('status', 'active')
            ->where('id', '!=', $user->id)
            ->count();

        if ($remaining === 0) {
            abort(422, 'This is the last active administrator. Assign the administrator role to another user first.');
        }
    }
}
