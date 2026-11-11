<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Security\AuditLogger;
use App\Services\Security\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Token authentication (Sanctum) with a two-step login when 2FA is enabled:
 *   POST /api/auth/login          -> { two_factor_required: true, challenge_token }
 *   POST /api/auth/2fa/verify     -> { token }
 */
final class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'password' => $data['password'],
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $this->audit->log(null, 'user.registered', $user, null, ['email' => $user->email], 'API registration');

        return response()->json([
            'user' => new UserResource($user),
            'token' => $user->createToken($data['device_name'] ?? 'api')->plainTextToken,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $user = User::query()->where('email', strtolower($data['email']))->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['Invalid credentials.']]);
        }

        if ($user->status === UserStatus::Suspended) {
            return response()->json(['message' => 'Account suspended.'], 403);
        }

        if ($user->hasTwoFactorEnabled()) {
            $challenge = Str::random(64);
            Cache::put("auth.2fa.{$challenge}", ['user_id' => $user->id, 'device' => $data['device_name'] ?? 'api'], now()->addMinutes(5));

            return response()->json([
                'two_factor_required' => true,
                'challenge_token' => $challenge,
            ]);
        }

        return response()->json([
            'user' => new UserResource($user),
            'token' => $this->issueToken($user, $data['device_name'] ?? 'api'),
        ]);
    }

    public function verifyTwoFactor(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        $data = $request->validate([
            'challenge_token' => ['required', 'string', 'size:64'],
            'code' => ['required', 'string', 'max:20'],
        ]);

        $pending = Cache::pull("auth.2fa.{$data['challenge_token']}");
        if (! $pending) {
            return response()->json(['message' => 'Challenge expired. Please log in again.'], 422);
        }

        $user = User::query()->findOrFail($pending['user_id']);

        if (! $twoFactor->verifyUserCode($user, $data['code'])) {
            return response()->json(['message' => 'Invalid two-factor code.'], 422);
        }

        return response()->json([
            'user' => new UserResource($user),
            'token' => $this->issueToken($user, $pending['device']),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => new UserResource($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    private function issueToken(User $user, string $deviceName): string
    {
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => request()->ip()])->saveQuietly();

        $abilities = $user->isAdmin() ? ['admin'] : ['user'];

        return $user->createToken($deviceName, $abilities)->plainTextToken;
    }
}
