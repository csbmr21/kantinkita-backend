<?php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $guarded = ['id'];

    protected static function booted()
    {
        static::saving(function ($user) {
            // Sync role string → role_id (canonical direction)
            if ($user->isDirty('role') && !$user->isDirty('role_id')) {
                $roleModel = \App\Models\Role::where('slug', $user->role)->first();
                if ($roleModel) {
                    $user->role_id = $roleModel->id;
                }
            }
            // Sync role_id → role string (reverse sync for consistency)
            if ($user->isDirty('role_id') && !$user->isDirty('role')) {
                $roleModel = \App\Models\Role::find($user->role_id);
                if ($roleModel) {
                    $user->role = $roleModel->slug;
                }
            }
        });
    }

    protected $appends = ['photo_url'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'email_notif'       => 'boolean',
        'wa_notif'          => 'boolean',
        'status'            => 'boolean',
        'is_deleted'        => 'boolean',
        'profile_completed' => 'boolean',
    ];

    public function tenant() { return $this->hasOne(Tenant::class); }
    public function orders() { return $this->hasMany(Order::class); }
    public function activityLogs() { return $this->hasMany(ActivityLog::class); }
    public function staffTenants() { return $this->belongsToMany(Tenant::class, 'tenant_user'); }
    public function permissions() { return $this->belongsToMany(Permission::class)->withTimestamps(); }
    public function assignedRole() { return $this->belongsTo(Role::class, 'role_id'); }

    /**
     * Get all permissions for this user (Role + Direct Overrides)
     */
    public function getAllPermissions()
    {
        $rolePermissions = $this->assignedRole ? $this->assignedRole->permissions : collect();
        return $rolePermissions->merge($this->permissions)->unique('id');
    }

    /**
     * Check if user has a specific permission by slug
     */
    public function hasPermission(string $slug): bool
    {
        return $this->getAllPermissions()->contains('slug', $slug);
    }

    public function getPhotoUrlAttribute(): ?string
    {
        if (!$this->photo) return null;
        if (filter_var($this->photo, FILTER_VALIDATE_URL)) return $this->photo;
        return asset('storage/' . $this->photo);
    }

    public function scopeActive(Builder $query) { return $query->where('status', 1)->where('is_deleted', 0); }

    /**
     * Canonical role slug — always prefer the relationship, fallback to legacy column.
     * Use this method everywhere instead of accessing $user->role directly.
     */
    public function getRoleSlug(): string
    {
        return strtolower($this->assignedRole?->slug ?? $this->role ?? 'customer');
    }

    // Role checks use the canonical getRoleSlug() method
    public function isAdmin(): bool    { 
        $role = $this->getRoleSlug();
        return $role === 'admin' || $role === 'administrator'; 
    }
    public function isOwner(): bool    { 
        $role = $this->getRoleSlug();
        return $role === 'owner' || $role === 'merchant'; 
    }
    public function isStaff(): bool    { 
        $role = $this->getRoleSlug();
        return $role === 'staff' || $role === 'kasir'; 
    }
    public function isCustomer(): bool { 
        return $this->getRoleSlug() === 'customer'; 
    }
}
