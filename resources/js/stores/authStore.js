import { getToken, setToken, clearToken } from '../api/token';

const authState = {
    token: getToken(),
    user: null,
};

export function getAuthToken() {
    return authState.token || getToken();
}

export function setAuthToken(token) {
    authState.token = token || null;
    setToken(token);
}

export function clearAuthToken() {
    authState.token = null;
    clearToken();
}

export function getCurrentUser() {
    return authState.user;
}

export function setCurrentUser(user) {
    authState.user = user || null;
}
