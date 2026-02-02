# User Registration & Authentication System

## Overview
This document outlines the complete user registration, authentication, and approval workflows for all user types in the system.

---

## User Types

### 1. **Admin**
- **Role**: `admin`
- **Creation Method**: Manual database seeding or direct database creation
- **Access**: Full system access
- **Portal**: Admin Dashboard (`/admin/*`)
- **No Public Registration**: Admins are created internally only

### 2. **Parent**
- **Role**: `parent`
- **Creation Method**: 
  - Direct registration via `/register`
  - Upgrade from `guest_parent` via onboarding flow
- **Access**: Parent portal with full features
- **Portal**: Parent Portal (`/portal/*`)
- **Registration Process**: Standard email/password registration

### 3. **Guest Parent**
- **Role**: `guest_parent`
- **Creation Method**: Created during guest checkout flow
- **Access**: Limited portal access (can view purchases, assessments they bought)
- **Portal**: Parent Portal (`/portal/*`) with restrictions
- **Upgrade Path**: Can complete profile to become full `parent` role
- **Registration Process**: 
  1. Email verification with OTP
  2. Minimal information (email, child names)
  3. Created during checkout process

### 4. **Teacher**
- **Role**: `teacher`
- **Creation Method**: Self-registration with admin approval (NEW)
- **Access**: Teacher portal
- **Portal**: Teacher Portal (`/teacher/*`)
- **Registration Process**: 
  1. Self-registration with OTP verification
  2. Admin approval required
  3. Cannot login until approved

---

## Registration Flows

## 1. Parent Registration Flow

### Routes
- **GET** `/register` - Registration page
- **POST** `/register` - Create parent account

### Process
1. User visits `/register`
2. Fills out form:
   - Name
   - Email
   - Password
   - Password Confirmation
3. Account created with `role = 'parent'`
4. Automatically logged in
5. Full access to parent portal

### Implementation
**Controller**: `RegisteredUserController@store`
**Model**: `User` with `role = 'parent'`
**No Approval Required**: Immediate access

---

## 2. Guest Parent Registration Flow

### Routes
- **POST** `/checkout/send-code` - Send verification code
- **POST** `/checkout/verify-code` - Verify code and create account

### Process
1. User starts checkout without logging in
2. Enters email address
3. Receives 6-digit OTP via email (`GuestVerificationCode`)
4. Verifies OTP (expires in 5 minutes)
5. Enters child information
6. Account created with `role = 'guest_parent'`
7. Automatically logged in
8. Can complete purchase

### Implementation
**Controller**: `CheckoutController@sendGuestCode` and `verifyGuestCode`
**Model**: `User` with `role = 'guest_parent'`
**Child**: Associated `Child` records created
**Access**: Can view purchases and access bought content only

### Upgrade to Full Parent
**Route**: `/guest/complete-profile`
**Process**:
1. Guest parent clicks "Complete Profile" link
2. Fills out additional information
3. Account upgraded to `role = 'parent'`
4. Full portal access granted

**Controller**: `GuestOnboardingController@store`

---

## 3. Teacher Registration Flow (NEW)

### Routes
- **POST** `/teacher/send-otp` - Send verification code to teacher email
- **POST** `/teacher/verify-otp` - Verify the OTP
- **POST** `/teacher/register` - Complete teacher registration
- **GET** `/teacher-applications/pending` (Admin) - View pending applications
- **POST** `/teacher-applications/{task}/approve` (Admin) - Approve teacher
- **POST** `/teacher-applications/{task}/reject` (Admin) - Reject teacher

### Process

#### Step 1: Email Verification
1. Teacher enters email address
2. System checks if email already exists
3. Generates 6-digit OTP
4. Stores OTP in session with 5-minute expiry
5. Sends email via `GuestVerificationCode` mailable
6. Teacher enters OTP
7. System verifies OTP and marks email as verified

#### Step 2: Registration
1. Teacher fills out form:
   - Name
   - Email (pre-filled)
   - Password
   - Password Confirmation
   - Mobile Number (optional)
   - Qualifications
   - Experience
   - Specialization
2. System creates User with:
   - `role = 'teacher'`
   - `metadata.status = 'pending_approval'`
   - `metadata.qualifications`
   - `metadata.experience`
   - `metadata.specialization`
   - `metadata.applied_at`
3. Creates AdminTask for approval
4. Sends "Application Received" email to teacher

#### Step 3: Admin Review
1. Admin sees task in AdminTask list
2. Reviews teacher application details
3. Clicks Approve or Reject

#### Step 4: Approval
**If Approved:**
1. `metadata.status` updated to `'approved'`
2. `metadata.approved_at` and `approved_by` recorded
3. Teacher receives "Teacher Approved" email with login details
4. Teacher can now login and access teacher portal

**If Rejected:**
1. `metadata.status` updated to `'rejected'`
2. `metadata.rejected_at` and `rejected_by` recorded
3. Teacher receives polite rejection email
4. Account exists but cannot login

### Implementation
**Controller**: `TeacherController`
**Model**: `User` with `role = 'teacher'` and JSON `metadata` column
**Approval System**: Uses `AdminTask` model
**Emails**: 
- `TeacherApplicationReceived`
- `TeacherApproved`
- `TeacherRejected`

### Metadata Structure
```json
{
  "status": "pending_approval|approved|rejected",
  "qualifications": "string",
  "experience": "string",
  "specialization": "string",
  "applied_at": "ISO timestamp",
  "approved_at": "ISO timestamp",
  "approved_by": 123,
  "rejected_at": "ISO timestamp",
  "rejected_by": 123
}
```

---

## Authentication & Login

### Login Route
- **GET** `/login` - Login page
- **POST** `/login` - Authenticate user

### Login Process
1. User enters email and password
2. System authenticates credentials
3. **For Teachers**: Checks `metadata.status`
   - If `pending_approval`: Login denied with message
   - If `rejected`: Login denied with message
   - If `approved`: Login successful
4. User redirected based on role:
   - `admin` → `/admin/admin-dashboard`
   - `teacher` → `/teacher/dashboard`
   - `parent` → `/portal/*`
   - `guest_parent` → `/portal/*` (limited)

### Role-Based Access Control
**Middleware**: `RoleMiddleware`

**Admin Routes** (`/admin/*`):
- Requires `role = 'admin'`

**Teacher Routes** (`/teacher/*`):
- Requires `role = 'teacher'`
- Requires `metadata.status = 'approved'`

**Parent Routes** (`/portal/*`):
- Allows `role = 'parent'` or `role = 'guest_parent'`
- Some features restricted for `guest_parent`

---

## Email Notifications

### Parent Registration
- No email (immediate access)

### Guest Parent Registration
- **GuestVerificationCode**: OTP for email verification

### Teacher Registration
1. **GuestVerificationCode**: OTP for email verification
2. **TeacherApplicationReceived**: Confirmation of application submission
3. **TeacherApproved**: Welcome email with login details
4. **TeacherRejected**: Polite rejection notification

---

## Database Schema

### Users Table
```sql
id                  BIGINT
name                VARCHAR(255)
email               VARCHAR(255) UNIQUE
password            VARCHAR(255)
role                ENUM('admin', 'parent', 'guest_parent', 'teacher')
mobile_number       VARCHAR(20) NULLABLE
metadata            JSON NULLABLE  -- For teacher-specific data
created_at          TIMESTAMP
updated_at          TIMESTAMP
```

### AdminTask Table (For Teacher Approvals)
```sql
id                  BIGINT
type                VARCHAR(255)  -- 'teacher_approval'
title               VARCHAR(255)
description         TEXT
status              ENUM('pending', 'completed', 'cancelled')
metadata            JSON  -- Contains user_id, teacher details
created_at          TIMESTAMP
updated_at          TIMESTAMP
completed_at        TIMESTAMP NULLABLE
```

---

## Security Features

### 1. OTP Verification
- 6-digit random OTP
- 5-minute expiry
- Session-based storage
- Rate limiting: 6 attempts per minute

### 2. Email Verification
- Required for guest_parent
- Required for teacher
- Prevents spam registrations

### 3. Admin Approval (Teachers)
- Prevents unauthorized teacher access
- Allows vetting of qualifications
- Creates audit trail

### 4. Password Requirements
- Minimum 8 characters
- Confirmed via password_confirmation field

### 5. Role-Based Access
- Middleware enforces role restrictions
- Teachers with pending/rejected status cannot access system
- Guest parents have limited feature access

---

## Frontend Pages Needed

### Public Pages
1. **Parent Registration** - `/register` (EXISTS)
2. **Teacher Registration** - `/teacher/register` (NEEDS CREATION)
   - Email input + OTP verification
   - Registration form with qualifications, experience, specialization
   - Success message with "wait for approval" notice

### Admin Pages
1. **Teacher Applications Dashboard** (NEEDS CREATION)
   - List of pending teacher applications
   - Application details view
   - Approve/Reject buttons
   - Search and filter capabilities

### Teacher Pages
1. **Teacher Portal** - `/teacher/*` (EXISTS)
   - Dashboard
   - Students management
   - Lesson management
   - etc.

---

## API Endpoints Summary

### Public Endpoints
```
POST /register                        - Parent registration
POST /checkout/send-code              - Guest parent OTP
POST /checkout/verify-code            - Guest parent verification
POST /teacher/send-otp                - Teacher OTP
POST /teacher/verify-otp              - Teacher OTP verification
POST /teacher/register                - Teacher registration
POST /login                           - Authentication
```

### Admin Endpoints
```
GET  /teacher-applications/pending    - List pending teachers
POST /teacher-applications/{task}/approve  - Approve teacher
POST /teacher-applications/{task}/reject   - Reject teacher
```

### Protected Endpoints
```
GET  /guest/complete-profile          - Guest onboarding page
POST /guest/complete-profile          - Upgrade guest to parent
```

---

## Comparison Matrix

| Feature | Parent | Guest Parent | Teacher | Admin |
|---------|--------|--------------|---------|-------|
| Self Registration | ✅ Yes | ✅ Yes (via checkout) | ✅ Yes (with approval) | ❌ No |
| Email Verification | ❌ No | ✅ Yes (OTP) | ✅ Yes (OTP) | N/A |
| Admin Approval | ❌ No | ❌ No | ✅ Yes | N/A |
| Immediate Access | ✅ Yes | ✅ Yes (limited) | ❌ No | ✅ Yes |
| Full Portal Access | ✅ Yes | ❌ Limited | ✅ Yes (after approval) | ✅ Yes |
| Upgrade Path | N/A | ✅ To Parent | N/A | N/A |
| Password Required | ✅ Yes | ✅ Yes | ✅ Yes | ✅ Yes |
| Additional Info | Basic | Basic + Children | Qualifications | N/A |

---

## Best Practices

### 1. For Teachers
- Always check `metadata.status` before granting access
- Send email notifications at every step
- Store application details in AdminTask metadata
- Keep audit trail of approvals/rejections

### 2. For Guest Parents
- Minimize friction during checkout
- Allow purchases without full registration
- Provide clear upgrade path to full parent
- Limit features appropriately

### 3. For Security
- Use OTP for email verification
- Implement rate limiting
- Expire OTP after 5 minutes
- Clear session data after use
- Hash all passwords

### 4. For Admin
- Provide clear application review interface
- Show all teacher details for informed decisions
- Allow bulk actions for efficiency
- Maintain audit trail

---

## Future Enhancements

1. **Email Verification for All Users**
   - Add email verification for parent registration too
   - Prevent spam accounts

2. **Social Login**
   - Google OAuth
   - Facebook OAuth
   - Apple Sign In

3. **Two-Factor Authentication**
   - SMS-based 2FA
   - Authenticator app support

4. **Teacher Portfolio**
   - Allow teachers to upload certificates
   - Video introduction
   - Teaching samples

5. **Automated Approval**
   - AI-based qualification verification
   - Automatic approval for verified credentials

6. **Guest Parent Analytics**
   - Track conversion rate from guest to parent
   - Identify drop-off points

---

## Troubleshooting

### Teacher Cannot Login
**Problem**: Teacher registered but cannot login
**Solution**: Check `metadata.status` - must be `'approved'`
**Admin Action**: Go to pending applications and approve

### OTP Not Received
**Problem**: User didn't receive OTP email
**Solution**: 
1. Check spam folder
2. Verify email address is correct
3. Check mail configuration in `.env`
4. Check session storage is working

### Guest Parent Access Issues
**Problem**: Guest parent cannot access features
**Solution**: 
1. Check if feature is restricted for guest_parent
2. Guide them to complete profile upgrade

### Email Already Exists
**Problem**: Cannot register with existing email
**Solution**:
1. User should use password reset
2. Admin can check existing account status
3. For teachers, check if already has pending application

---

## Testing Checklist

### Parent Registration
- [ ] Can register with valid credentials
- [ ] Cannot register with existing email
- [ ] Password validation works
- [ ] Automatically logged in after registration
- [ ] Has full portal access

### Guest Parent Flow
- [ ] OTP sent successfully
- [ ] OTP verification works
- [ ] OTP expires after 5 minutes
- [ ] Account created with guest_parent role
- [ ] Can complete purchase
- [ ] Can upgrade to parent

### Teacher Flow
- [ ] OTP sent successfully
- [ ] OTP verification works
- [ ] Cannot register without OTP verification
- [ ] Application creates AdminTask
- [ ] Confirmation email sent
- [ ] Cannot login before approval
- [ ] Approval email sent when approved
- [ ] Can login after approval
- [ ] Rejection email sent when rejected
- [ ] Cannot login after rejection

### Security
- [ ] Rate limiting works
- [ ] Session expires properly
- [ ] Passwords are hashed
- [ ] Role middleware blocks unauthorized access
- [ ] CSRF protection enabled

---

## Conclusion

The system now supports four distinct user types with appropriate registration flows, security measures, and access controls. The teacher registration system provides a secure, admin-approved onboarding process while maintaining a good user experience for applicants.
