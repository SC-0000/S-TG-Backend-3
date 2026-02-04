const CART_TOKEN_KEY = 'spa_tg_cart_token';

function hasStorage() {
    return typeof window !== 'undefined' && !!window.localStorage;
}

export function getCartToken() {
    if (!hasStorage()) return null;
    return window.localStorage.getItem(CART_TOKEN_KEY);
}

export function setCartToken(token) {
    if (!hasStorage()) return;
    if (token) {
        window.localStorage.setItem(CART_TOKEN_KEY, token);
    } else {
        window.localStorage.removeItem(CART_TOKEN_KEY);
    }
}

export function clearCartToken() {
    if (!hasStorage()) return;
    window.localStorage.removeItem(CART_TOKEN_KEY);
}

export function cartTokenKey() {
    return CART_TOKEN_KEY;
}
