<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name', 'email', 'password', 'school_id', 'patient_type', 'contact_no', 'push_token',
    'first_name', 'middle_name', 'last_name', 'birthdate', 'sex', 'home_address', 'department', 'course',
])]
#[Hidden(['password', 'remember_token', 'push_token', 'school_id_verified_by_id'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** Roles that work at the clinic, as opposed to being treated by it. */
    public const STAFF_ROLES = ['admin', 'personnel'];

    /**
     * The clinic's professions, and how each is titled. UDD has one of each:
     * the head nurse (the admin account) and a dentist and school physician
     * (personnel accounts) who only see their own bookings.
     */
    public const PROFESSION_TITLES = [
        'nurse' => 'Nurse',
        'dentist' => 'Dentist',
        'physician' => 'School Physician',
    ];

    /** Letters after the name on a certificate's signature line. */
    private const PROFESSION_CREDENTIALS = [
        'nurse' => 'RN',
        'dentist' => 'DMD',
        'physician' => 'MD',
    ];

    /**
     * Sent with every user so the apps can title staff properly ("Nurse
     * Portia · Head Nurse") without knowing the profession rules.
     *
     * @var array<int, string>
     */
    protected $appends = ['display_name', 'job_title'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'birthdate' => 'date:Y-m-d',
            'school_id_verified_at' => 'datetime',
        ];
    }

    /**
     * A patient's `name` is always rebuilt from the name parts, so the
     * queue, visit records, certificates and audit log — which all print
     * `name` — can never drift from what the patient typed. Staff accounts
     * have no name parts and keep the name they were created with.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user) {
            if ($user->first_name && $user->last_name) {
                $user->name = self::joinName($user->first_name, $user->middle_name, $user->last_name);
            }

            // A check of one ID number says nothing about another: any change
            // to it, by whoever and through whichever screen, needs checking
            // again — unless the same save is the check itself.
            if ($user->isDirty('school_id') && ! $user->isDirty('school_id_verified_at')) {
                $user->school_id_verified_at = null;
                $user->school_id_verified_by_id = null;
            }
        });
    }

    /**
     * Whether the nurse has checked this patient's ID number against their
     * UDD ID card. Until then it is only what the patient typed.
     */
    public function hasVerifiedSchoolId(): bool
    {
        return $this->school_id !== null && $this->school_id_verified_at !== null;
    }

    /** Record that a staff member checked the ID number against the card. */
    public function markSchoolIdVerified(User $by): void
    {
        $this->school_id_verified_at = now();
        $this->school_id_verified_by_id = $by->id;
    }

    public static function joinName(string $first, ?string $middle, string $last): string
    {
        return implode(' ', array_filter([trim($first), $middle ? trim($middle) : null, trim($last)]));
    }

    /**
     * Whether this account works at the clinic. Route middleware already
     * gates who may call an endpoint; this is for the controllers that then
     * have to tell "any patient's record" from "my own record".
     */
    public function isStaff(): bool
    {
        return in_array($this->role, self::STAFF_ROLES, true);
    }

    /** "Nurse Portia", "Dr. Marco Villanueva"; anyone else by plain name. */
    protected function displayName(): Attribute
    {
        return Attribute::get(fn (): string => match ($this->profession) {
            'nurse' => "Nurse {$this->name}",
            'dentist', 'physician' => "Dr. {$this->name}",
            default => (string) $this->name,
        });
    }

    /** "Head Nurse", "Dentist", "School Physician" — null for non-clinicians. */
    protected function jobTitle(): Attribute
    {
        return Attribute::get(function (): ?string {
            if ($this->profession === 'nurse' && $this->role === 'admin') {
                return 'Head Nurse';
            }

            return self::PROFESSION_TITLES[$this->profession] ?? null;
        });
    }

    /** "Portia, RN" for a certificate's signature line. */
    public function signatureName(): string
    {
        $credentials = self::PROFESSION_CREDENTIALS[$this->profession] ?? null;

        return $credentials ? "{$this->name}, {$credentials}" : $this->name;
    }

    /**
     * Whether everything the clinic's health record asks for is on file.
     * Booking is refused until it is, so the nurse never confirms a booking
     * for someone she could not identify.
     */
    public function hasCompleteHealthRecord(): bool
    {
        return $this->first_name && $this->last_name && $this->birthdate && $this->sex
            && $this->home_address && $this->department && $this->school_id
            && ($this->patient_type !== 'student' || $this->course);
    }

    /** Professionals patients can book with (have a profession, any role). */
    public function scopeProviders(Builder $query): Builder
    {
        return $query->whereNotNull('profession')->whereIn('role', self::STAFF_ROLES);
    }

    /** @return HasMany<ProviderSchedule, $this> */
    public function providerSchedules(): HasMany
    {
        return $this->hasMany(ProviderSchedule::class);
    }
}
