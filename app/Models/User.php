<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar',
        'phone',
        'notification_preferences',
    ];

    public function institutionTeachers(): HasMany
    {
        return $this->hasMany(InstitutionTeacher::class);
    }

    public function membershipFor(int $institutionId): ?InstitutionTeacher
    {
        return $this->institutionTeachers()
            ->where('institution_id', $institutionId)
            ->where('status', 'active')
            ->first();
    }

    public function isActiveMemberOf(int $institutionId): bool
    {
        return $this->membershipFor($institutionId) !== null;
    }

    public function isAdminOf(int $institutionId): bool
    {
        return $this->membershipFor($institutionId)?->role === 'admin';
    }

    /**
     * Preferencias por tipo por defecto encendidas: null explícito o ausencia
     * de la clave significan "activado" (módulo 18 — Notificaciones).
     */
    public function wantsNotification(string $type): bool
    {
        return ($this->notification_preferences[$type] ?? true) !== false;
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

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
            'notification_preferences' => 'array',
        ];
    }
}
