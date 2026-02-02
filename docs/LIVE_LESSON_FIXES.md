# Live Lesson Feature Fixes

## Issues Fixed

### 1. LiveKit Auto-Disconnect (Critical)
**Problem**: LiveKit room was disconnecting on every component re-render due to cleanup in useEffect.

**Solution**: 
- Added `useRef` to track LiveKit room instance persistently
- Removed automatic disconnect from useEffect cleanup
- Only disconnect when user explicitly clicks "Leave Session" button
- Added confirmation dialog before leaving
- Removed `sessionState` from useEffect dependencies to prevent re-initialization

**Files Changed**:
- `resources/js/admin/Pages/Teacher/LiveLesson/TeacherPanel.jsx`

### 2. Echo/Reverb Configuration for Production
**Problem**: Hard-coded localhost values prevented WebSocket connections in production.

**Solution**:
- Auto-detect production vs development environment
- Use current page protocol (http/https) to determine WebSocket scheme
- Fallback to sensible defaults if environment variables missing
- Added debug logging for connection troubleshooting
- Added proper authentication endpoint configuration

**Files Changed**:
- `resources/js/echo.js`

## Production Environment Setup

### Required Environment Variables

Your production `.env` file should have:

```env
# Broadcasting (Reverb)
BROADCAST_CONNECTION=reverb

# Reverb Server Configuration
REVERB_APP_ID=your-app-id
REVERB_APP_KEY=your-app-key
REVERB_APP_SECRET=your-app-secret
REVERB_HOST=your-domain.com
REVERB_PORT=443
REVERB_SCHEME=https

# Frontend (exposed via Vite)
VITE_REVERB_APP_KEY=${REVERB_APP_KEY}
VITE_REVERB_HOST=${REVERB_HOST}
VITE_REVERB_PORT=${REVERB_PORT}
VITE_REVERB_SCHEME=${REVERB_SCHEME}

# LiveKit Configuration
LIVEKIT_API_KEY=your-livekit-key
LIVEKIT_API_SECRET=your-livekit-secret
LIVEKIT_URL=wss://your-livekit-server.com
```

### Web Server Configuration

#### For Apache (with mod_proxy_wstunnel enabled)

Add to your virtual host or `.htaccess`:

```apache
# Enable WebSocket proxying
RewriteEngine On

# Proxy WebSocket connections to Reverb
RewriteCond %{HTTP:Upgrade} websocket [NC]
RewriteCond %{HTTP:Connection} upgrade [NC]
RewriteRule ^/app/(.*)$ ws://127.0.0.1:8080/app/$1 [P,L]

# Proxy regular Reverb requests
ProxyPass /app/ http://127.0.0.1:8080/app/
ProxyPassReverse /app/ http://127.0.0.1:8080/app/
```

#### For Nginx

Add to your server block:

```nginx
location /app/ {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

### Running Reverb

Start the Reverb WebSocket server:

```bash
php artisan reverb:start --host=127.0.0.1 --port=8080
```

For production, use a process manager like Supervisor:

```ini
[program:reverb]
command=php /path/to/your/project/artisan reverb:start --host=127.0.0.1 --port=8080
directory=/path/to/your/project
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/path/to/your/project/storage/logs/reverb.log
```

## Build Assets

After making these changes, rebuild your frontend assets:

```bash
npm run build
```

## Testing Checklist

### Local Development
- [ ] Reverb server is running (`php artisan reverb:start`)
- [ ] Can connect to live lesson as teacher
- [ ] LiveKit audio initializes successfully
- [ ] Microphone toggle works
- [ ] Slide navigation works
- [ ] Closing panel doesn't disconnect LiveKit immediately
- [ ] "Leave Session" button properly disconnects

### Production
- [ ] Reverb server is running and supervised
- [ ] WebSocket proxy is configured correctly
- [ ] SSL/TLS certificates are valid
- [ ] Can connect to live lesson as teacher
- [ ] WebSocket connects successfully (check browser console)
- [ ] LiveKit connects successfully
- [ ] No "CLIENT_REQUEST_LEAVE" errors on navigation
- [ ] Teacher can stay in session while navigating other UI elements
- [ ] "Leave Session" confirmation works

## Browser Console Logs

### Expected Successful Logs

```
[Echo] Initializing with config: { host: "your-domain.com", scheme: "https", port: 443, forceTLS: true, isProduction: true }
[Echo] WebSocket connected successfully
[TeacherPanel] Initialized
[TeacherPanel] Initializing LiveKit audio
[TeacherPanel] Got LiveKit token
[TeacherPanel] LiveKit audio initialized successfully
```

### Common Errors and Solutions

#### WebSocket Connection Failed
```
Firefox can't establish a connection to wss://your-domain.com/app/...
```
**Solution**: Check web server proxy configuration and ensure Reverb is running.

#### LiveKit Disconnect Loop
```
[TeacherPanel] Cleaning up LiveKit room
reason: "CLIENT_REQUEST_LEAVE"
```
**Solution**: Already fixed - update to latest code.

#### CORS/Authentication Errors
**Solution**: Ensure `allowed_origins` in `config/reverb.php` includes your domain:
```php
'allowed_origins' => ['*'], // Or specific domains: ['https://your-domain.com']
```

## Notes

- The CSP frame-ancestors warning is only relevant if embedding external content (like chess.com) - can be ignored otherwise
- Cookie SameSite warnings are expected for third-party embeds - ignore unless using cross-domain cookies
- Asset abort warnings (NS_BINDING_ABORTED) should stop occurring after LiveKit fix
- Local development uses `BROADCAST_CONNECTION=log` which is fine for testing
