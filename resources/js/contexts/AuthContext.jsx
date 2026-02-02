import React, { createContext, useContext, useState, useEffect, useCallback } from 'react';
import { getToken, setToken, clearToken } from '../api/token';
import { apiClient, ApiError } from '../api/client';

const AuthContext = createContext(null);

export const useAuth = () => {
    const context = useContext(AuthContext);
    if (!context) {
        throw new Error('useAuth must be used within an AuthProvider');
    }
    return context;
};

export const AuthProvider = ({ children, initialUser = null }) => {
    const [user, setUser] = useState(initialUser);
    const [token, setTokenState] = useState(getToken());
    const [loading, setLoading] = useState(!initialUser && !!getToken());
    const [initialized, setInitialized] = useState(!!initialUser);

    // Fetch current user on mount if we have a token but no user
    useEffect(() => {
        if (token && !user && !initialized) {
            fetchUser();
        } else if (!token) {
            setLoading(false);
            setInitialized(true);
        }
    }, [token, user, initialized]);

    const fetchUser = useCallback(async () => {
        try {
            setLoading(true);
            const response = await apiClient.get('/me');
            setUser(response.data);
            setInitialized(true);
        } catch (error) {
            console.error('Failed to fetch user:', error);
            // If we can't fetch the user, the token might be invalid
            if (error instanceof ApiError && error.status === 401) {
                logout();
            }
        } finally {
            setLoading(false);
        }
    }, []);

    const login = useCallback(async (credentials) => {
        try {
            const response = await apiClient.post('/auth/login', credentials, {
                useToken: false, // Don't send token for login
            });
            
            const { token: newToken, user: userData } = response.data;
            
            setToken(newToken);
            setTokenState(newToken);
            setUser(userData);
            
            return { success: true, data: response.data };
        } catch (error) {
            return { 
                success: false, 
                error: error instanceof ApiError ? error : new Error('Login failed'),
            };
        }
    }, []);

    const register = useCallback(async (registrationData) => {
        try {
            const response = await apiClient.post('/auth/register', registrationData, {
                useToken: false,
            });
            
            const { token: newToken, user: userData } = response.data;
            
            if (newToken) {
                setToken(newToken);
                setTokenState(newToken);
            }
            
            if (userData) {
                setUser(userData);
            }
            
            return { success: true, data: response.data };
        } catch (error) {
            return { 
                success: false, 
                error: error instanceof ApiError ? error : new Error('Registration failed'),
            };
        }
    }, []);

    const logout = useCallback(async (skipServerCall = false) => {
        try {
            if (!skipServerCall) {
                await apiClient.post('/auth/logout');
            }
        } catch (error) {
            console.error('Logout error:', error);
        } finally {
            clearToken();
            setTokenState(null);
            setUser(null);
        }
    }, []);

    const updateUser = useCallback(async (userData) => {
        try {
            const response = await apiClient.patch('/me', userData);
            setUser(response.data);
            return { success: true, data: response.data };
        } catch (error) {
            return { 
                success: false, 
                error: error instanceof ApiError ? error : new Error('Update failed'),
            };
        }
    }, []);

    const refreshUser = useCallback(async () => {
        return await fetchUser();
    }, [fetchUser]);

    const isAuthenticated = !!user && !!token;
    const hasRole = useCallback((role) => {
        if (!user) return false;
        if (Array.isArray(role)) {
            return role.some(r => user.role === r || user.roles?.includes(r));
        }
        return user.role === role || user.roles?.includes(role);
    }, [user]);

    const value = {
        user,
        token,
        loading,
        initialized,
        isAuthenticated,
        login,
        register,
        logout,
        updateUser,
        refreshUser,
        hasRole,
    };

    return (
        <AuthContext.Provider value={value}>
            {children}
        </AuthContext.Provider>
    );
};

export default AuthProvider;