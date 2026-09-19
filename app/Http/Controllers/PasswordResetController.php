<?php

namespace App\Http\Controllers;

use App\Mail\PasswordResetOtp;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;

/**
 * OTP-based password reset.
 *
 * The emailed code is the only secret, so it is treated like one: stored as a
 * hash, valid for a short window, single use, and burned after a small number
 * of wrong guesses. A successful reset also revokes every existing API token,
 * on the assumption that a reset may be a response to a compromise.
 */
class PasswordResetController extends Controller
{
    public function __construct(protected AuditLogger $audit)
    {
    }

    /**
     * Issue a one-time code and email it to the account holder.
     */
    public function sendOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
        ]);

        $otp = $this->generateOtp();
        $expiresInMinutes = $this->expiresInMinutes();

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $data['email']],
            [
                'token' => Hash::make($otp),
                'attempts' => 0,
                'created_at' => now(),
            ],
        );

        Mail::to($data['email'])->send(new PasswordResetOtp($otp, $expiresInMinutes));

        $this->audit->log('password_reset.otp_sent', [
            'email' => $data['email'],
        ], User::where('email', $data['email'])->first());

        return response()->json([
            'status' => true,
            'message' => "A verification code has been sent to your email. It expires in {$expiresInMinutes} minutes.",
        ]);
    }

    /**
     * Verify the code and set the new password.
     */
    public function verifyAndReset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'otp' => ['required', 'string', 'digits:'.$this->otpLength()],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $record = DB::table('password_reset_tokens')
            ->where('email', $data['email'])
            ->first();

        if ($record === null) {
            return $this->failure('No verification code has been requested for this email.');
        }

        if ($this->hasExpired($record)) {
            $this->forgetToken($data['email']);

            return $this->failure('This verification code has expired. Please request a new one.');
        }

        if ($record->attempts >= $this->maxAttempts()) {
            $this->forgetToken($data['email']);

            return $this->failure('Too many incorrect attempts. Please request a new code.');
        }

        if (! Hash::check($data['otp'], $record->token)) {
            DB::table('password_reset_tokens')
                ->where('email', $data['email'])
                ->increment('attempts');

            $remaining = max(0, $this->maxAttempts() - ($record->attempts + 1));

            return $this->failure(
                $remaining > 0
                    ? "The verification code is incorrect. {$remaining} attempt(s) remaining."
                    : 'The verification code is incorrect. Please request a new code.'
            );
        }

        $user = User::where('email', $data['email'])->firstOrFail();

        DB::transaction(function () use ($user, $data) {
            $user->password = Hash::make($data['password']);
            $user->setRememberToken(null);
            $user->save();

            // A reset may be a response to a compromise: drop every issued token
            // so any session the attacker holds dies with the old password.
            $user->tokens()->delete();

            $this->forgetToken($data['email']);
        });

        $this->audit->log('password_reset.completed', [
            'email' => $user->email,
            'username' => $user->username,
        ], $user);

        return response()->json([
            'status' => true,
            'message' => 'Your password has been reset successfully. Please log in with your new password.',
        ]);
    }

    /**
     * A cryptographically secure zero-padded numeric code.
     */
    protected function generateOtp(): string
    {
        $length = $this->otpLength();
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    /**
     * @param  object{created_at: string|null}  $record
     */
    protected function hasExpired(object $record): bool
    {
        if ($record->created_at === null) {
            return true;
        }

        return Carbon::parse($record->created_at)
            ->addMinutes($this->expiresInMinutes())
            ->isPast();
    }

    protected function forgetToken(string $email): void
    {
        DB::table('password_reset_tokens')->where('email', $email)->delete();
    }

    protected function failure(string $message, int $status = JsonResponse::HTTP_UNPROCESSABLE_ENTITY): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $message,
        ], $status);
    }

    protected function otpLength(): int
    {
        return (int) config('garment_factory.password_reset.otp_length', 6);
    }

    protected function expiresInMinutes(): int
    {
        return (int) config('garment_factory.password_reset.expires_in_minutes', 10);
    }

    protected function maxAttempts(): int
    {
        return (int) config('garment_factory.password_reset.max_attempts', 5);
    }
}
