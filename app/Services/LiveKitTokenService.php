<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Log;

class LiveKitTokenService
{
    private $apiKey;
    private $apiSecret;
    private $url;

    public function __construct()
    {
        $this->apiKey = config('services.livekit.api_key');
        $this->apiSecret = config('services.livekit.api_secret');
        $this->url = config('services.livekit.url');

        Log::info('[LiveKitTokenService] Initialized', [
            'api_key' => $this->apiKey ? 'SET' : 'NOT SET',
            'api_secret' => $this->apiSecret ? 'SET' : 'NOT SET',
            'url' => $this->url
        ]);
    }

    /**
     * Generate LiveKit access token for voice/video communication
     *
     * @param string $roomName The room name
     * @param string $participantName The participant's display name
     * @param string $participantIdentity Unique participant identity (usually user ID)
     * @param array $permissions Custom permissions
     * @param int $expireTime Token expiration time in seconds (default: 6 hours)
     * @return array
     */
    public function generateToken(
        string $roomName,
        string $participantName,
        string $participantIdentity,
        array $permissions = [],
        array $metadata = [],
        int $expireTime = 21600
    ) {
        Log::info('[LiveKitTokenService] Generating token', [
            'room' => $roomName,
            'participant' => $participantName,
            'identity' => $participantIdentity,
            'metadata' => $metadata,
            'expire_time' => $expireTime
        ]);

        if (!$this->apiKey || !$this->apiSecret) {
            Log::error('[LiveKitTokenService] LiveKit credentials not configured');
            throw new \Exception('LiveKit credentials not configured. Please set LIVEKIT_API_KEY and LIVEKIT_API_SECRET in .env');
        }

        try {
            $now = time();
            $expireAt = $now + $expireTime;

            // Build video grant with permissions
            $videoGrant = [
                'roomJoin' => true,
                'room' => $roomName,
                'canPublish' => $permissions['can_publish'] ?? true,
                'canSubscribe' => $permissions['can_subscribe'] ?? true,
                'canPublishData' => $permissions['can_publish_data'] ?? true,
            ];

            // Build JWT claims according to LiveKit spec
            $claims = [
                'exp' => $expireAt,
                'iss' => $this->apiKey,
                'nbf' => $now,
                'sub' => $participantIdentity,
                'name' => $participantName,
                'video' => $videoGrant,
            ];

            // Add metadata if provided
            if (!empty($metadata)) {
                $claims['metadata'] = json_encode($metadata);
            }

            // Generate JWT token
            $token = JWT::encode($claims, $this->apiSecret, 'HS256');

            Log::info('[LiveKitTokenService] Token generated successfully', [
                'room' => $roomName,
                'identity' => $participantIdentity
            ]);

            return [
                'token' => $token,
                'url' => $this->url,
                'room_name' => $roomName,
                'participant_name' => $participantName,
                'participant_identity' => $participantIdentity,
                'expire_time' => $expireAt,
                'force_opus_only' => config('livekit.force_opus_only', true), // ✅ Add codec compatibility flag
            ];
        } catch (\Exception $e) {
            Log::error('[LiveKitTokenService] Failed to generate token', [
                'error' => $e->getMessage(),
                'room' => $roomName,
                'identity' => $participantIdentity
            ]);
            throw $e;
        }
    }

    /**
     * Generate token for a live lesson session
     *
     * @param \App\Models\LiveLessonSession $session
     * @param \App\Models\User $user
     * @param array $permissions
     * @return array
     */
    public function generateSessionToken($session, $user, array $permissions = [], array $metadata = [])
    {
        $roomName = "live-lesson-{$session->id}";
        
        // ✅ Determine if user is a teacher/admin or student
        $isTeacher = in_array($user->role, ['teacher', 'admin']);
        
        if ($isTeacher) {
            // ✅ For teachers: Use teacher identity and name
            $participantName = $user->name;
            $participantIdentity = "teacher-{$user->id}";
            
            $metadata = array_merge($metadata, [
                'role' => 'teacher',
                'user_id' => $user->id,
                'teacher_id' => $user->id,
                'teacher_name' => $user->name
            ]);
            
            Log::info('[LiveKitTokenService] Generating teacher session token', [
                'session_id' => $session->id,
                'user_id' => $user->id,
                'teacher_id' => $user->id,
                'identity' => $participantIdentity,
                'room' => $roomName,
                'metadata' => $metadata
            ]);
        } else {
            // ✅ For students: Use child identity and name
            // Check if specific child_id was passed in metadata (for multi-child support)
            if (isset($metadata['child_id'])) {
                $child = $user->children()->where('id', $metadata['child_id'])->first();
                
                if (!$child) {
                    Log::error('[LiveKitTokenService] Invalid child_id in metadata', [
                        'user_id' => $user->id,
                        'requested_child_id' => $metadata['child_id'],
                        'available_children' => $user->children->pluck('id')
                    ]);
                    throw new \Exception('Invalid child selection. Please select a valid child.');
                }
                
                Log::info('[LiveKitTokenService] ✅ Using specific child from metadata', [
                    'child_id' => $child->id,
                    'child_name' => $child->child_name, // ✅ Fixed: Use child_name field
                    'parent_name' => $child->user->name // ✅ DEBUG: Show parent name for comparison
                ]);
            } else {
                // Fall back to first child
                $child = $user->children()->first();
                
                if (!$child) {
                    Log::error('[LiveKitTokenService] No child profile found for user', [
                        'user_id' => $user->id,
                        'user_email' => $user->email,
                        'user_role' => $user->role
                    ]);
                    throw new \Exception('No child profile found. Please ensure your child profile is set up correctly.');
                }
                
                Log::info('[LiveKitTokenService] ⚠️ Using first child (default - no child_id specified)', [
                    'child_id' => $child->id,
                    'child_name' => $child->child_name, // ✅ Fixed: Use child_name field
                    'parent_name' => $child->user->name // ✅ DEBUG: Show parent name for comparison
                ]);
            }
            
            $participantName = $child->child_name; // ✅ Use child's actual name from child_name field
            $participantIdentity = "child-{$child->id}"; // ✅ Child ID
            
            $metadata = array_merge($metadata, [
                'role' => 'student',
                'user_id' => $user->id,
                'child_id' => $child->id,
                'child_name' => $child->child_name // ✅ Use child's actual name, not parent's name
            ]);
            
            Log::info('[LiveKitTokenService] ✅ Generating student session token', [
                'session_id' => $session->id,
                'user_id' => $user->id,
                'parent_name' => $user->name, // ✅ DEBUG: Show parent name for comparison
                'child_id' => $child->id,
                'child_name' => $child->child_name, // ✅ Fixed: Use child_name field
                'parent_name_from_relation' => $child->user->name, // ✅ DEBUG: Show what we were using before (wrong)
                'identity' => $participantIdentity,
                'room' => $roomName,
                'metadata' => $metadata
            ]);
        }

        return $this->generateToken(
            $roomName,
            $participantName,
            $participantIdentity,
            $permissions,
            $metadata
        );
    }

    /**
     * Validate if LiveKit is properly configured
     *
     * @return bool
     */
    public function isConfigured()
    {
        return !empty($this->apiKey) && !empty($this->apiSecret) && !empty($this->url);
    }
}
