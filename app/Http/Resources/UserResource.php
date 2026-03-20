<?php

namespace App\Http\Resources;

use App\Models\TeacherProfile;

class UserResource extends ApiResource
{
    public function toArray($request): array
    {
        $metadata = $this->metadata ?? [];
        $originalRole = $metadata['original_role'] ?? null;

        // Determine if admin has a teacher profile (check for both current admins and admins in teacher mode)
        $isAdminRole = in_array($this->role, ['admin', 'super_admin']);
        $isAdminInTeacherMode = $this->role === 'teacher' && in_array($originalRole, ['admin', 'super_admin']);
        $hasTeacherProfile = ($isAdminRole || $isAdminInTeacherMode)
            ? TeacherProfile::where('user_id', $this->id)->exists()
            : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'phone' => $this->mobile_number,
            'mobile_number' => $this->mobile_number,
            'avatar_url' => $this->avatar_path
                ? '/storage/' . $this->avatar_path
                : null,
            'current_organization_id' => $this->current_organization_id,
            'email_verified_at' => $this->email_verified_at,

            // Role switching context
            'has_teacher_profile' => $hasTeacherProfile,
            'original_role' => $originalRole,
            'is_in_teacher_mode' => $isAdminInTeacherMode,

            // Include organization with branding when available
            'organization' => $this->when(
                $this->currentOrganization,
                new OrganizationResource($this->currentOrganization)
            ),
        ];
    }
}
