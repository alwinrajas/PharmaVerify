<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\TokenExpiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email'))->first();

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'The email address or password is incorrect.',
            ]);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages([
                'email' => 'This account has been deactivated. Please contact your administrator.',
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $user->tokens()->where('name', TokenExpiry::WEB_TOKEN_NAME)->delete();

        $tokenName = $request->string('device_name')->toString() ?: TokenExpiry::WEB_TOKEN_NAME;

        // Null unless an expiry window has been configured, in which case the
        // token simply does not expire — see config/security.php.
        $token = $user->createToken($tokenName, ['*'], TokenExpiry::for($tokenName))->plainTextToken;

        activity('auth')->causedBy($user)->log('Signed in');

        return ApiResponse::success([
            'token' => $token,
            'user' => new UserResource($user->load(['roles', 'shops'])),
        ], 'Signed in successfully.');
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(
            new UserResource($request->user()->load(['roles', 'shops']))
        );
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        activity('auth')->causedBy($request->user())->log('Signed out');

        return ApiResponse::success(null, 'Signed out successfully.');
    }
}
