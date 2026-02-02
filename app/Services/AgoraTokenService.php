<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class AgoraTokenService
{
    private $appId;
    private $appCertificate;

    public function __construct()
    {
        $this->appId = config('services.agora.app_id');
        $this->appCertificate = config('services.agora.app_certificate');
        
        Log::info('[AgoraTokenService] Initialized', [
            'app_id' => $this->appId ? 'SET' : 'NOT SET',
            'app_certificate' => $this->appCertificate ? 'SET' : 'NOT SET'
        ]);
    }

    /**
     * Generate RTC token for voice/video communication
     *
     * @param string $channelName The channel name
     * @param int $userId The user ID
     * @param string $role 'publisher' or 'subscriber'
     * @param int $expireTime Token expiration time in seconds (default: 24 hours)
     * @return array
     */
    public function generateRtcToken($channelName, $userId, $role = 'publisher', $expireTime = 86400)
    {
        Log::info('[AgoraTokenService] Generating RTC token', [
            'channel' => $channelName,
            'user_id' => $userId,
            'role' => $role,
            'expire_time' => $expireTime
        ]);

        if (!$this->appId || !$this->appCertificate) {
            Log::error('[AgoraTokenService] Agora credentials not configured');
            throw new \Exception('Agora credentials not configured. Please set AGORA_APP_ID and AGORA_APP_CERTIFICATE in .env');
        }

        $privilegeExpireTs = time() + $expireTime;
        
        // Role: 1 = publisher (can publish audio/video), 2 = subscriber (can only subscribe)
        $roleValue = $role === 'publisher' ? 1 : 2;

        try {
            // Generate token using Agora token builder
            $token = $this->buildToken($channelName, $userId, $roleValue, $privilegeExpireTs);
            
            Log::info('[AgoraTokenService] RTC token generated successfully', [
                'channel' => $channelName,
                'user_id' => $userId
            ]);

            return [
                'token' => $token,
                'app_id' => $this->appId,
                'channel_name' => $channelName,
                'user_id' => $userId,
                'expire_time' => $privilegeExpireTs
            ];
        } catch (\Exception $e) {
            Log::error('[AgoraTokenService] Failed to generate token', [
                'error' => $e->getMessage(),
                'channel' => $channelName,
                'user_id' => $userId
            ]);
            throw $e;
        }
    }

    /**
     * Build Agora RTC token
     * 
     * @param string $channelName
     * @param int $uid
     * @param int $role
     * @param int $expireTimestamp
     * @return string
     */
    private function buildToken($channelName, $uid, $role, $expireTimestamp)
    {
        // Agora token structure
        $version = '007';
        $randomInt = mt_rand();
        $timestamp = time();
        
        // Build message
        $message = $this->buildMessage($channelName, $uid, $role, $expireTimestamp, $timestamp, $randomInt);
        
        // Sign message
        $signature = hash_hmac('sha256', $message, $this->appCertificate, true);
        
        // Encode token
        $token = $version . base64_encode(pack('V', $randomInt) . pack('V', $timestamp) . $signature . $message);
        
        return $token;
    }

    /**
     * Build message for token generation
     */
    private function buildMessage($channelName, $uid, $role, $expireTimestamp, $timestamp, $salt)
    {
        return pack('V', $salt) . 
               pack('V', $timestamp) . 
               pack('V', $expireTimestamp) . 
               pack('v', strlen($channelName)) . 
               $channelName . 
               pack('V', $uid) . 
               pack('V', $role);
    }

    /**
     * Generate token for a live lesson session
     *
     * @param \App\Models\LiveLessonSession $session
     * @param \App\Models\User $user
     * @param string $role
     * @return array
     */
    public function generateSessionToken($session, $user, $role = 'publisher')
    {
        $channelName = "live-lesson-{$session->id}";
        $userId = $user->id;

        Log::info('[AgoraTokenService] Generating session token', [
            'session_id' => $session->id,
            'user_id' => $userId,
            'channel' => $channelName,
            'role' => $role
        ]);

        return $this->generateRtcToken($channelName, $userId, $role);
    }

    /**
     * Validate if Agora is properly configured
     *
     * @return bool
     */
    public function isConfigured()
    {
        return !empty($this->appId) && !empty($this->appCertificate);
    }
}
