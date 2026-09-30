<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'school_id', 'patient_type', 'contact_no', 'push_token'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** Roles that work at the clinic, as opposed to being treated by it. */
    public const STAFF_ROLES = ['admin', 'personnel'];

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
        ];
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
}
