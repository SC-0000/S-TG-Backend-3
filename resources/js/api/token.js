const TOKEN_KEY = 'spa_tg_api_token';

function hasStorage() {
    return typeof window !== 'undefined' && !!window.localStorage;
}

export function getToken() {
    if (!hasStorage()) return null;
    return window.localStorage.getItem(TOKEN_KEY);
}

export function setToken(token) {
    if (!hasStorage()) return;
    if (token) {
        window.localStorage.setItem(TOKEN_KEY, token);
    } else {
        window.localStorage.removeItem(TOKEN_KEY);
    }
}

export function clearToken() {
    if (!hasStorage()) return;
    window.localStorage.removeItem(TOKEN_KEY);
}

export function tokenKey() {
    return TOKEN_KEY;
}
