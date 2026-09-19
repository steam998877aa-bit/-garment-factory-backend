<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Re-authenticates the current user before a sensitive action.
 *
 * The caller must send their own password with the request. This is checked on
 * every sensitive call rather than cached for a window, so a stolen token alone
 * is not enough to delete an employee or move stock.
 *
 * Because that turns every sensitive endpoint into a password oracle, failed
 * attempts are rate limited per user.
 */
trait ConfirmsPassword
{
    /**
     * Maximum failed confirmations before the user is locked out briefly.
     */
    protected int $passwordConfirmationMaxAttempts = 5;

    /**
     * Lockout window, in seconds, once the attempt limit is reached.
     */
    protected int $passwordConfirmationDecaySeconds = 300;

    /**
     * Verify the request carries the authenticated user's current password.
     *
     * @param  string  $field  Which request field holds the password. Password
     *                         changes send it as current_password; every other
     *                         sensitive operation sends it as password.
     *
     * @throws ValidationException 422 when missing or wrong, 429 when rate limited.
     */
    protected function confirmPassword(Request $request, string $field = 'password'): void
    {
        $request->validate(
            [$field => ['required', 'string']],
            [$field.'.required' => 'كلمة المرور مطلوبة لتأكيد هذه العملية.'],
        );

        $key = $this->passwordConfirmationThrottleKey($request);

        if (RateLimiter::tooManyAttempts($key, $this->passwordConfirmationMaxAttempts)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                $field => ["تم تجاوز عدد المحاولات المسموح بها. حاول مرة أخرى بعد {$seconds} ثانية."],
            ])->status(429);
        }

        if (! Hash::check($request->input($field), $request->user()->password)) {
            RateLimiter::hit($key, $this->passwordConfirmationDecaySeconds);

            throw ValidationException::withMessages([
                $field => ['كلمة المرور غير صحيحة.'],
            ]);
        }

        RateLimiter::clear($key);
    }

    /**
     * Throttle failed confirmations per user, falling back to the client address.
     */
    protected function passwordConfirmationThrottleKey(Request $request): string
    {
        return 'password-confirmation:'.($request->user()?->getKey() ?? $request->ip());
    }
}
