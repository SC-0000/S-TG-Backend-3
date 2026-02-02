import axios from 'axios';
window.axios = axios;

// Configure axios to use the correct base URL
// When running Vite dev server, we need to explicitly point to Laravel backend
window.axios.defaults.baseURL = window.location.origin;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;

// Get CSRF token from meta tag and configure axios
let token = document.head.querySelector('meta[name="csrf-token"]');
if (token) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
} else {
    console.error('CSRF token not found in page. Make sure you have <meta name="csrf-token"> in your layout.');
}

// Helper function to refresh CSRF token
function refreshCsrfToken() {
    const token = document.head.querySelector('meta[name="csrf-token"]');
    if (token) {
        window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
        return token.content;
    }
    return null;
}

// Add interceptor to handle 419 errors (CSRF token mismatch)
window.axios.interceptors.response.use(
    response => response,
    error => {
        // Check if it's a 419 error (Page Expired / CSRF token mismatch)
        if (error.response && error.response.status === 419) {
            const originalRequest = error.config;
            
            // Prevent infinite retry loop
            if (!originalRequest._retry) {
                originalRequest._retry = true;
                
                // Try to refresh the CSRF token from the meta tag
                const newToken = refreshCsrfToken();
                
                if (newToken) {
                    // Update the failed request with the new token
                    originalRequest.headers['X-CSRF-TOKEN'] = newToken;
                    
                    // Retry the original request with the new token
                    return window.axios(originalRequest);
                }
            }
            
            // If we couldn't refresh or already retried, reload the page to get a fresh session
            console.error('CSRF token refresh failed. Reloading page...');
            window.location.reload();
        }
        
        return Promise.reject(error);
    }
);

// Add socket ID to all axios requests for proper broadcasting
// This allows Laravel's ->toOthers() to work correctly
window.axios.interceptors.request.use(config => {
    if (window.Echo && window.Echo.socketId()) {
        config.headers['X-Socket-Id'] = window.Echo.socketId();
    }
    return config;
});

// Debug logging for axios requests
if (import.meta.env.DEV) {
    window.axios.interceptors.request.use(request => {
        console.log('[Axios Request]', {
            method: request.method?.toUpperCase(),
            url: request.url,
            baseURL: request.baseURL,
            fullURL: `${request.baseURL || ''}${request.url}`,
            data: request.data
        });
        return request;
    });

    window.axios.interceptors.response.use(
        response => {
            console.log('[Axios Response Success]', {
                url: response.config.url,
                status: response.status,
                data: response.data
            });
            return response;
        },
        error => {
            console.error('[Axios Response Error]', {
                url: error.config?.url,
                status: error.response?.status,
                message: error.message,
                data: error.response?.data
            });
            return Promise.reject(error);
        }
    );
}

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

import './echo';
