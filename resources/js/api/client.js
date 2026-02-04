import { API_BASE_URL } from './config';
import { getToken, setToken, clearToken } from './token';
import { getCartToken, setCartToken } from './cartToken';

export class ApiError extends Error {
    constructor(message, { status, errors, meta, data } = {}) {
        super(message || 'API request failed');
        this.name = 'ApiError';
        this.status = status;
        this.errors = errors || [];
        this.meta = meta || null;
        this.data = data || null;
    }
}

function buildUrl(path, params) {
    const base = API_BASE_URL.startsWith('http')
        ? API_BASE_URL
        : `${window.location.origin}${API_BASE_URL.startsWith('/') ? '' : '/'}${API_BASE_URL}`;
    const normalizedBase = base.endsWith('/') ? base : `${base}/`;
    const normalizedPath = typeof path === 'string'
        ? (path.startsWith('/') ? path.slice(1) : path)
        : path;

    const url = new URL(normalizedPath, normalizedBase);

    if (params && typeof params === 'object') {
        Object.entries(params).forEach(([key, value]) => {
            if (value === undefined || value === null) return;
            if (Array.isArray(value)) {
                value.forEach(item => url.searchParams.append(`${key}[]`, item));
                return;
            }
            url.searchParams.set(key, value);
        });
    }

    return url.toString();
}

function isFormData(value) {
    return typeof FormData !== 'undefined' && value instanceof FormData;
}

async function parseJson(response) {
    const contentType = response.headers.get('content-type') || '';
    if (!contentType.includes('application/json')) {
        return null;
    }
    try {
        return await response.json();
    } catch (error) {
        return null;
    }
}

let isRefreshing = false;
let refreshSubscribers = [];
const ENABLE_REFRESH = import.meta.env.VITE_AUTH_REFRESH === 'true';

function onTokenRefreshed(newToken) {
    refreshSubscribers.forEach(callback => callback(newToken));
    refreshSubscribers = [];
}

function subscribeTokenRefresh(callback) {
    refreshSubscribers.push(callback);
}

async function refreshToken() {
    if (!ENABLE_REFRESH) {
        throw new Error('Token refresh not supported');
    }

    if (isRefreshing) {
        return new Promise((resolve) => {
            subscribeTokenRefresh((token) => {
                resolve(token);
            });
        });
    }

    isRefreshing = true;

    try {
        const response = await fetch(buildUrl('/auth/refresh'), {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Authorization': `Bearer ${getToken()}`,
            },
        });

        if (!response.ok) {
            throw new Error('Token refresh failed');
        }

        const payload = await parseJson(response);
        const newToken = payload?.data?.token || payload?.token;

        if (newToken) {
            setToken(newToken);
            onTokenRefreshed(newToken);
            return newToken;
        }

        throw new Error('No token in refresh response');
    } catch (error) {
        clearToken();
        onTokenRefreshed(null);
        throw error;
    } finally {
        isRefreshing = false;
    }
}

export async function request(path, options = {}) {
    const {
        method = 'GET',
        headers = {},
        params,
        body,
        credentials = 'omit',
        useToken = true,
        retry = true,
    } = options;

    const url = buildUrl(path, params);
    const requestHeaders = new Headers(headers);
    requestHeaders.set('Accept', 'application/json');

    if (useToken) {
        const token = getToken();
        if (token) {
            requestHeaders.set('Authorization', `Bearer ${token}`);
        }
    }

    const cartToken = getCartToken();
    if (cartToken) {
        requestHeaders.set('X-Cart-Token', cartToken);
    }

    let requestBody = body;
    if (body && !isFormData(body)) {
        requestHeaders.set('Content-Type', 'application/json');
        requestBody = JSON.stringify(body);
    }

    const response = await fetch(url, {
        method,
        headers: requestHeaders,
        body: requestBody,
        credentials,
    });

    const responseCartToken = response.headers.get('X-Cart-Token');
    if (responseCartToken) {
        setCartToken(responseCartToken);
    }

    const payload = await parseJson(response);

    // Handle 401 - Try to refresh token and retry
    if (response.status === 401 && retry && useToken && !path.includes('/auth/')) {
        if (!ENABLE_REFRESH) {
            clearToken();
            throw new ApiError(payload?.message || response.statusText, {
                status: response.status,
                errors: payload?.errors,
                meta: payload?.meta,
                data: payload?.data,
            });
        }

        try {
            const newToken = await refreshToken();
            if (newToken) {
                // Retry the request with the new token
                return request(path, { ...options, retry: false });
            }
        } catch (refreshError) {
            // Token refresh failed, throw the original 401 error
            throw new ApiError(payload?.message || response.statusText, {
                status: response.status,
                errors: payload?.errors,
                meta: payload?.meta,
                data: payload?.data,
            });
        }
    }

    if (!response.ok) {
        throw new ApiError(payload?.message || response.statusText, {
            status: response.status,
            errors: payload?.errors,
            meta: payload?.meta,
            data: payload?.data,
        });
    }

    if (payload && typeof payload === 'object' && 'data' in payload && 'meta' in payload) {
        return payload;
    }

    return {
        data: payload,
        meta: { status: response.status },
        errors: [],
    };
}

export const apiClient = {
    get: (path, options) => request(path, { ...options, method: 'GET' }),
    post: (path, body, options) => request(path, { ...options, method: 'POST', body }),
    put: (path, body, options) => request(path, { ...options, method: 'PUT', body }),
    patch: (path, body, options) => request(path, { ...options, method: 'PATCH', body }),
    delete: (path, options) => request(path, { ...options, method: 'DELETE' }),
};
