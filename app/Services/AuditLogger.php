<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Single entry point for writing audit entries.
 *
 * Every sensitive operation records who did it (both the user id and a copy of
 * the username, so the entry survives the account being deleted), what they
 * did, the specifics, and when — created_at carries full date and time down to
 * the second.
 */
class AuditLogger
{
    /**
     * Attributes never written to the trail, whatever the caller passes.
     *
     * @var list<string>
     */
    public const REDACTED = ['password', 'remember_token', 'current_password', 'new_password'];

    /**
     * Attributes that change on every save and say nothing useful.
     *
     * @var list<string>
     */
    protected const NOISE = ['updated_at', 'created_at'];

    /**
     * Record a sensitive operation.
     *
     * @param  string  $action  Machine-readable action key, e.g. "employee.deleted".
     * @param  array<string, mixed>|string  $details  What happened, in specifics.
     */
    public function log(string $action, array|string $details, ?User $user = null): AuditLog
    {
        $user ??= Auth::user();

        return AuditLog::create([
            'user_id' => $user?->getKey(),
            'username' => $user?->username,
            'action' => $action,
            'details' => is_array($details)
                ? $this->encode($this->redact($details))
                : $details,
        ]);
    }

    /**
     * Record an update, capturing what each field changed from and to.
     *
     * `$before` must be the model's attributes as they were *before* saving —
     * `$model->getOriginal()` read before the write. Eloquent resyncs originals
     * during save, so reading it afterwards would report the new values as old.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $context  Extra identifying detail (ids, names).
     */
    public function logUpdate(
        string $action,
        Model $model,
        array $before,
        array $context = [],
        ?User $user = null,
    ): AuditLog {
        $changes = $this->diff($model, $before);

        return $this->log($action, $context + [
            'changed' => array_keys($changes),
            'changes' => $changes,
        ], $user);
    }

    /**
     * Field-by-field from/to for everything the save actually altered.
     *
     * @param  array<string, mixed>  $before
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public function diff(Model $model, array $before): array
    {
        $changes = [];

        foreach ($model->getChanges() as $field => $new) {
            if (in_array($field, self::NOISE, true)) {
                continue;
            }

            $changes[$field] = in_array($field, self::REDACTED, true)
                ? ['from' => '[redacted]', 'to' => '[redacted]']
                : ['from' => $this->scalar($before[$field] ?? null), 'to' => $this->scalar($new)];
        }

        return $changes;
    }

    /**
     * Strip anything that must never reach the trail.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    protected function redact(array $details): array
    {
        foreach ($details as $key => $value) {
            if (in_array($key, self::REDACTED, true)) {
                $details[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $details[$key] = $this->redact($value);
            }
        }

        return $details;
    }

    /**
     * Reduce a value to something readable inside a json log line.
     */
    protected function scalar(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_object($value)) {
            return method_exists($value, '__toString') ? (string) $value : get_class($value);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    protected function encode(array $details): string
    {
        return json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
