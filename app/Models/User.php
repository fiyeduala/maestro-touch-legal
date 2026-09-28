<?php

namespace App\Models;

use App\Domain\Identity\Role;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\Email\Contracts\HasEmailAuthentication;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use SensitiveParameter;

class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasEmailAuthentication, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'timezone',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'app_authentication_secret',
        'app_authentication_recovery_codes',
    ];

    /** @var array<string>|null per-request cache of active role values */
    private ?array $roleCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'suspended_at' => 'datetime',
            'offboarded_at' => 'datetime',
            'last_login_at' => 'datetime',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
            'has_email_authentication' => 'boolean',
        ];
    }

    // --- Roles -------------------------------------------------------------

    public function roleGrants(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    public function activeRoleGrants(): HasMany
    {
        return $this->roleGrants()->whereNull('revoked_at');
    }

    /** @return list<string> */
    public function activeRoleValues(): array
    {
        return $this->roleCache ??= $this->activeRoleGrants()->pluck('role')
            ->map(fn (Role|string $r) => $r instanceof Role ? $r->value : $r)->unique()->values()->all();
    }

    /** @return list<Role> */
    public function roles(): array
    {
        return array_map(fn ($v) => Role::from($v), $this->activeRoleValues());
    }

    public function hasRole(Role ...$roles): bool
    {
        return (bool) array_intersect($this->activeRoleValues(), array_map(fn (Role $r) => $r->value, $roles));
    }

    public function isFullAdministrator(): bool
    {
        return $this->isActive() && $this->hasRole(...Role::fullAdministratorRoles());
    }

    public function isStaff(): bool
    {
        return $this->hasRole(...Role::staffRoles());
    }

    public function isClient(): bool
    {
        return $this->hasRole(Role::Client);
    }

    public function flushRoleCache(): void
    {
        $this->roleCache = null;
    }

    public function scopeWithActiveRole(Builder $query, Role ...$roles): Builder
    {
        return $query->whereHas('activeRoleGrants', fn ($q) => $q->whereIn('role', array_map(fn ($r) => $r->value, $roles)));
    }

    // --- Status ------------------------------------------------------------

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function isActive(): bool
    {
        return ! $this->isSuspended() && $this->offboarded_at === null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('suspended_at')->whereNull('offboarded_at');
    }

    // --- Relations ---------------------------------------------------------

    public function staffProfile(): HasOne
    {
        return $this->hasOne(StaffProfile::class);
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class)
            ->withPivot(['relationship', 'revoked_at'])
            ->wherePivotNull('revoked_at')
            ->withTimestamps();
    }

    public function matterMemberships(): HasMany
    {
        return $this->hasMany(MatterTeamMember::class)->whereNull('removed_at');
    }

    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assignee_id');
    }

    // --- Filament ----------------------------------------------------------

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin' && $this->isActive() && $this->isStaff();
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->forceFill(['app_authentication_secret' => $secret])->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->forceFill(['app_authentication_recovery_codes' => $codes])->save();
    }

    public function hasEmailAuthentication(): bool
    {
        return $this->has_email_authentication;
    }

    public function toggleEmailAuthentication(bool $condition): void
    {
        $this->forceFill(['has_email_authentication' => $condition])->save();
    }
}
