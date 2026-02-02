# LiveKit Migration Guide
## Migrating from Agora to Self-Hosted LiveKit

This guide covers the complete migration from Agora cloud service to self-hosted LiveKit for live lesson audio/video functionality.

---

## 📋 Overview

**What Changed:**
- ✅ Backend now uses `LiveKitTokenService` instead of `AgoraTokenService`
- ✅ JWT-based token generation (using Firebase JWT)
- ✅ Routes updated to support LiveKit tokens
- ⏳ Frontend still needs migration (next step)

**Why LiveKit?**
- **Cost**: Free (only bandwidth costs) vs Agora's per-minute pricing
- **Privacy**: 100% self-hosted - no data leaves your server
- **Control**: Full control over media server configuration
- **Similar API**: Very similar to Agora, making migration straightforward

---

## 🔧 Phase 1: Server Setup (Plesk/Ubuntu)

### 1.1 Install LiveKit Server

SSH into your Plesk server:

```bash
# Download and install LiveKit
curl -sSL https://get.livekit.io | bash

# Verify installation
livekit-server --version
```

### 1.2 Create Configuration Directory

```bash
# Create config directory
sudo mkdir -p /etc/livekit

# Create configuration file
sudo nano /etc/livekit/livekit.yaml
```

### 1.3 Configure LiveKit

Add this configuration to `/etc/livekit/livekit.yaml`:

```yaml
# Basic server configuration
port: 7880
bind_addresses:
  - "0.0.0.0"

# API keys - CHANGE THESE!
keys:
  livekit_api_key: your_api_secret_here

# RTC configuration
rtc:
  port_range_start: 50000
  port_range_end: 60000
  use_external_ip: true
  
# TURN server configuration (for NAT traversal)
turn:
  enabled: true
  domain: rtc.yourdomain.com
  tls_port: 5349
  
# Logging
logging:
  level: info
  sample: false

# Room configuration
room:
  auto_create: true
  empty_timeout: 300
  max_participants: 100
```

**Generate Secure API Keys:**

```bash
# Generate a random API key
openssl rand -hex 32

# Generate a random API secret
openssl rand -hex 32
```

Update the `keys` section with your generated values.

### 1.4 Create SystemD Service

Create `/etc/systemd/system/livekit.service`:

```bash
sudo nano /etc/systemd/system/livekit.service
```

Add this configuration:

```ini
[Unit]
Description=LiveKit Server
Documentation=https://docs.livekit.io
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory=/etc/livekit
ExecStart=/usr/local/bin/livekit-server --config /etc/livekit/livekit.yaml
Restart=always
RestartSec=10

# Security
NoNewPrivileges=true
PrivateTmp=true

# Logging
StandardOutput=journal
StandardError=journal
SyslogIdentifier=livekit

[Install]
WantedBy=multi-user.target
```

Enable and start the service:

```bash
# Reload systemd
sudo systemctl daemon-reload

# Enable auto-start on boot
sudo systemctl enable livekit

# Start the service
sudo systemctl start livekit

# Check status
sudo systemctl status livekit

# View logs
sudo journalctl -u livekit -f
```

### 1.5 Configure Plesk Reverse Proxy

1. **Create Subdomain in Plesk:**
   - Go to **Websites & Domains**
   - Click **Add Subdomain**
   - Enter: `rtc.yourdomain.com`
   - Point to any directory (we'll override with proxy)

2. **Add SSL Certificate:**
   - Select the subdomain
   - Go to **SSL/TLS Certificates**
   - Click **Get it free** (Let's Encrypt)

3. **Configure Nginx Proxy:**
   - Go to **Apache & Nginx Settings**
   - In the **Additional nginx directives** box, add:

```nginx
location / {
    proxy_pass http://127.0.0.1:7880;
    proxy_http_version 1.1;
    
    # WebSocket support
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    
    # Standard proxy headers
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    
    # Timeouts
    proxy_read_timeout 86400;
    proxy_send_timeout 86400;
}
```

### 1.6 Open Firewall Ports

```bash
# Allow HTTPS
sudo ufw allow 443/tcp

# Allow LiveKit HTTP (if needed)
sudo ufw allow 7880/tcp

# Allow RTC media ports
sudo ufw allow 50000:60000/udp

# Allow TURN
sudo ufw allow 3478/tcp
sudo ufw allow 3478/udp
sudo ufw allow 5349/tcp

# Reload firewall
sudo ufw reload
```

### 1.7 Test LiveKit Server

```bash
# Check if server is running
curl http://localhost:7880

# You should see a JSON response like:
# {"server":"LiveKit","version":"..."}
```

Test external access:
```bash
# Should return server info
curl https://rtc.yourdomain.com
```

---

## ⚙️ Phase 2: Laravel Configuration

### 2.1 Update Environment Variables

Edit your `.env` file:

```env
# Remove or comment out Agora credentials
# AGORA_APP_ID=your_agora_app_id
# AGORA_APP_CERTIFICATE=your_agora_certificate

# Add LiveKit credentials
LIVEKIT_API_KEY=your_api_key_here
LIVEKIT_API_SECRET=your_api_secret_here
LIVEKIT_URL=wss://rtc.yourdomain.com
```

**Important:** Use the same API key and secret you added to `livekit.yaml`.

### 2.2 Clear Configuration Cache

```bash
# Clear all caches
php artisan config:clear
php artisan cache:clear
php artisan route:clear

# Rebuild optimized config
php artisan config:cache
```

### 2.3 Test Token Generation

Create a test route to verify token generation:

```bash
php artisan tinker
```

```php
// Test token generation
$service = app(\App\Services\LiveKitTokenService::class);

// Generate a test token
$token = $service->generateToken(
    'test-room',
    'Test User',
    'user-123',
    ['can_publish' => true, 'can_subscribe' => true]
);

// Should return array with 'token', 'url', 'room_name', etc.
dd($token);
```

---

## 🎨 Phase 3: Frontend Migration (TODO)

**Note:** Frontend changes are not yet implemented. Here's what needs to be done:

### 3.1 Update Package Dependencies

```bash
npm uninstall agora-rtc-sdk-ng
npm install livekit-client
```

### 3.2 Update TeacherPanel.jsx

Find and replace Agora code (~line 112) with LiveKit implementation.

**Replace Agora initialization:**
```javascript
// OLD (Agora)
const AgoraRTC = (await import('agora-rtc-sdk-ng')).default;
const client = AgoraRTC.createClient({ mode: 'rtc', codec: 'vp8' });
await client.join(app_id, channel_name, token, user_id);

// NEW (LiveKit)
const { Room } = await import('livekit-client');
const room = new Room();
await room.connect(url, token);
```

**Replace microphone toggle:**
```javascript
// OLD (Agora)
const track = await AgoraRTC.createMicrophoneAudioTrack();
await client.publish([track]);

// NEW (LiveKit)
await room.localParticipant.setMicrophoneEnabled(true);
```

### 3.3 Update LivePlayer.jsx

Same changes as TeacherPanel.jsx for the student interface.

---

## ✅ Phase 4: Testing

### 4.1 Test Token Generation

Visit: `https://yourdomain.com/admin/live-sessions/{session_id}/livekit-token`

Expected response:
```json
{
  "token": "eyJhbGc...",
  "url": "wss://rtc.yourdomain.com",
  "room_name": "live-lesson-123",
  "participant_name": "Teacher Name",
  "participant_identity": "user-456",
  "expire_time": 1234567890
}
```

### 4.2 Test WebSocket Connection

```bash
# Install wscat for testing
npm install -g wscat

# Test WebSocket connection
wscat -c wss://rtc.yourdomain.com
```

### 4.3 Monitor Server Logs

```bash
# Watch LiveKit logs
sudo journalctl -u livekit -f

# Look for:
# - Client connections
# - Room creation
# - Participant joins/leaves
# - Any errors
```

---

## 🔍 Troubleshooting

### Issue: Cannot connect to LiveKit server

**Solution:**
1. Check if service is running: `sudo systemctl status livekit`
2. Check firewall: `sudo ufw status`
3. Test local connection: `curl http://localhost:7880`
4. Check Nginx proxy config in Plesk
5. Verify SSL certificate is active

### Issue: Token generation fails

**Solution:**
1. Verify `.env` credentials match `livekit.yaml`
2. Clear Laravel config cache: `php artisan config:clear`
3. Check logs: `sudo journalctl -u livekit -f`

### Issue: WebSocket connection fails

**Solution:**
1. Ensure Nginx proxy has WebSocket support (check `Connection "upgrade"`)
2. Verify SSL certificate is valid
3. Check browser console for errors
4. Test with `wscat -c wss://rtc.yourdomain.com`

### Issue: Audio not working

**Solution:**
1. Check browser permissions (microphone access)
2. Verify RTC ports are open (50000-60000 UDP)
3. Test TURN server: https://webrtc.github.io/samples/src/content/peerconnection/trickle-ice/
4. Check LiveKit logs for connection errors

---

## 📊 Migration Checklist

**Backend (Completed):**
- [x] Install Firebase JWT package
- [x] Create `LiveKitTokenService.php`
- [x] Update `config/services.php`
- [x] Update `LiveLessonController`
- [x] Update admin routes
- [x] Update parent routes

**Server Setup (Required):**
- [ ] Install LiveKit server binary
- [ ] Create `livekit.yaml` configuration
- [ ] Create SystemD service
- [ ] Configure Plesk reverse proxy
- [ ] Add SSL certificate
- [ ] Open firewall ports
- [ ] Test server connectivity

**Environment (Required):**
- [ ] Add `LIVEKIT_API_KEY` to `.env`
- [ ] Add `LIVEKIT_API_SECRET` to `.env`
- [ ] Add `LIVEKIT_URL` to `.env`
- [ ] Clear Laravel caches
- [ ] Test token generation

**Frontend (TODO):**
- [ ] Update `package.json` (remove Agora, add LiveKit)
- [ ] Update `TeacherPanel.jsx`
- [ ] Update `LivePlayer.jsx`
- [ ] Test audio in live sessions

---

## 📚 Additional Resources

- [LiveKit Documentation](https://docs.livekit.io)
- [LiveKit Server GitHub](https://github.com/livekit/livekit)
- [LiveKit Client SDK](https://docs.livekit.io/client-sdk-js/)
- [JWT Token Format](https://docs.livekit.io/guides/access-tokens/)

---

## 🆘 Support

If you encounter issues:

1. Check LiveKit logs: `sudo journalctl -u livekit -f`
2. Check Laravel logs: `storage/logs/laravel.log`
3. Check browser console for JS errors
4. Verify all configuration matches this guide

---

## 🎯 Next Steps

1. **Complete Server Setup** - Follow Phase 1 instructions
2. **Configure Environment** - Update `.env` with LiveKit credentials
3. **Frontend Migration** - Update React components to use LiveKit client
4. **Testing** - Thoroughly test with real live sessions

Once all phases are complete, you'll have a fully self-hosted real-time communication system with no recurring Agora costs!
