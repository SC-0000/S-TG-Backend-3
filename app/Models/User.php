<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;               // ← add this
use Illuminate\Auth\MustVerifyEmail as MustVerifyTrait;      // ← and this
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail  // ← implement interface
{
    use HasApiTokens, HasFactory, Notifiable, MustVerifyTrait;             // ← use the trait

    // Role constants
    public const ROLE_SUPER_ADMIN = 'super_admin';  // Platform-wide super administrator
    public const ROLE_ADMIN  = 'admin';
    public const ROLE_PARENT = 'parent';
    public const ROLE_BASIC  = 'basic';
    public const ROLE_GUEST_PARENT  = 'guest_parent';
    public const ROLE_TEACHER = 'teacher';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',  
        'email_verified_at',     // ← allow mass‐assignment of role
        'address_line1',
        'address_line2',
        'mobile_number',
        'billing_customer_id',
        'current_organization_id',  // For organization context
        'metadata',  // For role-specific data
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string,string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password'          => 'hashed',
        'metadata'          => 'array',
    ];

    /**
     * Relationship: a parent can have many children.
     */
    public function children()
    {
        return $this->hasMany(Child::class, 'user_id');
    }

    /**
     * Role check helpers
     */
    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isParent(): bool
    {
        return $this->role === self::ROLE_PARENT;
    }

    public function isBasic(): bool
    {
        return $this->role === self::ROLE_BASIC;
    }

    public function isTeacher(): bool
    {
        return $this->role === self::ROLE_TEACHER;
    }

    /**
     * Check if user is platform-level super admin
     * This is the highest privilege level with unrestricted access
     */
    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    /**
     * General-purpose role checker
     */
    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }
    public function permissions()
{
  return $this->hasMany(Permission::class);
}
public function applications()   // one-to-many
    {
        return $this->hasMany(Application::class);
    }
    public function subscriptions()
{
    return $this->belongsToMany(Subscription::class, 'user_subscriptions')
                ->withPivot(['starts_at','ends_at','status','child_id'])
                ->wherePivot('status','active');
}

    public function hasFeature(string $feature): bool
    {
        return $this->subscriptions()
                    ->whereJsonContains('features->'.$feature, true)
                    ->exists();
    }

    // Add: User has many Transactions
    public function transactions()
    {
        return $this->hasMany(\App\Models\Transaction::class, 'user_id');
    }

    /**
     * Get all organizations this user belongs to
     */
    public function organizations()
    {
        return $this->belongsToMany(Organization::class, 'organization_users')
                    ->withPivot(['role', 'status', 'invited_by', 'joined_at'])
                    ->withTimestamps();
    }

    /**
     * Get user's current organization
     */
    public function currentOrganization()
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    /**
     * Get organizations where user owns them
     */
    public function ownedOrganizations()
    {
        return $this->hasMany(Organization::class, 'owner_id');
    }

    /**
     * Check if user has role in specific organization
     */
    public function hasRoleInOrganization(string $role, $organizationId = null): bool
    {
        $orgId = $organizationId ?? $this->current_organization_id;
        if (!$orgId) return false;

        return $this->organizations()
                    ->wherePivot('organization_id', $orgId)
                    ->wherePivot('role', $role)
                    ->wherePivot('status', 'active')
                    ->exists();
    }

    /**
     * Get user's role in specific organization
     */
    public function getRoleInOrganization($organizationId = null): ?string
    {
        $orgId = $organizationId ?? $this->current_organization_id;
        if (!$orgId) return null;

        $organization = $this->organizations()
                             ->wherePivot('organization_id', $orgId)
                             ->wherePivot('status', 'active')
                             ->first();

        return $organization?->pivot->role;
    }

    /**
     * Switch user's current organization
     */
    public function switchOrganization($organizationId): bool
    {
        // Check if user belongs to this organization
        if (!$this->organizations()->where('organization_id', $organizationId)->exists()) {
            return false;
        }

        $this->update(['current_organization_id' => $organizationId]);
        return true;
    }

    /**
     * Check if user can access organization
     */
    public function canAccessOrganization($organizationId): bool
    {
        return $this->organizations()
                    ->where('organization_id', $organizationId)
                    ->wherePivot('status', 'active')
                    ->exists();
    }

    /**
     * Get active organizations only
     */
    public function activeOrganizations()
    {
        return $this->organizations()->wherePivot('status', 'active');
    }

    /**
     * Check if user is organization owner
     */
    public function isOrganizationOwner($organizationId = null): bool
    {
        $orgId = $organizationId ?? $this->current_organization_id;
        if (!$orgId) return false;

        return Organization::where('id', $orgId)
                          ->where('owner_id', $this->id)
                          ->exists();
    }

    /**
     * Check if user is super admin in current organization (organization-level)
     * Note: This is different from platform super admin (isSuperAdmin())
     */
    public function isOrgSuperAdmin($organizationId = null): bool
    {
        return $this->hasRoleInOrganization('super_admin', $organizationId);
    }

    /**
     * Check if user is org admin in current organization
     */
    public function isOrgAdmin($organizationId = null): bool
    {
        return $this->hasRoleInOrganization('org_admin', $organizationId);
    }

    /**
     * Get students directly assigned to this teacher
     */
    public function assignedStudents()
    {
        return $this->belongsToMany(Child::class, 'child_teacher', 'teacher_id', 'child_id')
                    ->withPivot(['assigned_by', 'assigned_at', 'notes', 'organization_id'])
                    ->withTimestamps();
    }
}
