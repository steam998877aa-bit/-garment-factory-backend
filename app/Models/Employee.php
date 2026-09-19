<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ON_LEAVE = 'on_leave';

    public const STATUS_RESIGNED = 'resigned';

    /**
     * Employment states a record may carry.
     *
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_ON_LEAVE,
        self::STATUS_RESIGNED,
    ];

    /**
     * Spellings accepted on input for a status that has more than one name.
     *
     * `inactive` and `resigned` describe the same state, so both are accepted
     * and stored as `resigned` — a client sending either gets the same result
     * instead of an integration failing on a word choice.
     *
     * @var array<string, string>
     */
    public const STATUS_ALIASES = [
        'inactive' => self::STATUS_RESIGNED,
    ];

    /** @use HasFactory<\Database\Factories\EmployeeFactory> */
    use HasFactory;

    /**
     * Default attribute values.
     *
     * The column carries the same default, but repeating it here is what makes
     * a freshly created record report `active` in the response it is created
     * with: without it the attribute is simply unset in memory and serialises
     * as null until the row is read back.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'fingerprint_id',
        'department',
        'status',
        'position',
        'shift',
        'vacation_balance',
        'address',
        'start_date',
        'documents',
        'id_card_image',
        'cv_file',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vacation_balance' => 'decimal:1',
            'start_date' => 'date',
        ];
    }

    /**
     * The stored form of a status given by a caller.
     *
     * Case and surrounding space are forgiven and known aliases resolved.
     * Returns null for anything that is not a status, which the validation
     * rules turn into a 422 rather than letting it reach the query.
     */
    public static function canonicalStatus(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        $status = strtolower(trim($status));
        $status = self::STATUS_ALIASES[$status] ?? $status;

        return in_array($status, self::STATUSES, true) ? $status : null;
    }

    /**
     * Attendance records captured for this employee.
     *
     * @return HasMany<Attendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * The portal login for this employee, if one has been created.
     *
     * @return HasOne<User, $this>
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }
}
