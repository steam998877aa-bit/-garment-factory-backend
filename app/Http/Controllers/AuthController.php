<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use ConfirmsPassword;

    public function __construct(protected AuditLogger $audit)
    {
    }

    /**
     * Authenticate a user by username or email and issue a Sanctum token.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ]);

        $user = User::with('role')
            ->where('username', $credentials['login'])
            ->orWhere('email', $credentials['login'])
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken($credentials['device_name'] ?? 'api');

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * Revoke the access token used for the current request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Change the signed-in user's own password.
     *
     * The current password is re-entered and checked through the same
     * confirmation path the other sensitive endpoints use, so wrong guesses are
     * rate limited per user rather than being an unlimited password oracle.
     *
     * Every other token for the account is revoked afterwards. The token making
     * the request survives, so the caller is not logged out of the device they
     * are standing at, while any session elsewhere dies with the old password.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $this->confirmPassword($request, 'current_password');

        $request->validate([
            'new_password' => [
                'required',
                'confirmed',
                'different:current_password',
                Password::defaults(),
            ],
        ], [
            'new_password.different' => 'The new password must be different from the current one.',
        ]);

        $user = $request->user();
        $current = $user->currentAccessToken();

        $revoked = $user->tokens()
            ->when($current !== null && isset($current->id), fn ($query) => $query->whereKeyNot($current->id))
            ->count();

        $user->password = $request->input('new_password');
        $user->setRememberToken(null);
        $user->save();

        $user->tokens()
            ->when($current !== null && isset($current->id), fn ($query) => $query->whereKeyNot($current->id))
            ->delete();

        $this->audit->log('password.changed', [
            'user_id' => $user->getKey(),
            'username' => $user->username,
            'revoked_tokens' => $revoked,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Your password has been changed. Other devices have been signed out.',
            'revoked_tokens' => $revoked,
        ]);
    }

    /**
     * Return the authenticated user together with their role.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->userPayload($request->user()->load('role')),
        ]);
    }

    /**
     * Shape the user data returned by the authentication endpoints.
     *
     * @return array<string, mixed>
     */
    protected function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role' => $user->role ? [
                'id' => $user->role->id,
                'name' => $user->role->name,
                'description' => $user->role->description,
            ] : null,
        ];
    }
}
