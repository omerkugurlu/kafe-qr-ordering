import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { apiPost, apiRequest } from '../lib/api.js';

export interface AppUser {
    id: number;
    name: string;
    email: string;
    role: string;
    cafeId: number | null;
}

interface AuthContextValue {
    user: AppUser | null;
    csrfToken: string | null;
    loading: boolean;
    refresh: () => Promise<void>;
    login: (email: string, password: string, customerToken?: string) => Promise<void>;
    registerCustomer: (input: { token: string; name: string; email: string; password: string }) => Promise<void>;
    logout: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
    const [user, setUser] = useState<AppUser | null>(null);
    const [csrfToken, setCsrfToken] = useState<string | null>(null);
    const [loading, setLoading] = useState(true);

    const refresh = useCallback(async () => {
        try {
            const data = await apiRequest<{ user: AppUser | null; csrfToken: string | null }>('/api/auth/me');
            setUser(data.user);
            setCsrfToken(data.csrfToken);
        } catch {
            setUser(null);
            setCsrfToken(null);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        void refresh();
    }, [refresh]);

    const login = useCallback(async (email: string, password: string, customerToken?: string) => {
        const endpoint = customerToken ? '/api/auth/customer/login' : '/api/auth/login';
        const data = await apiPost<{ user: AppUser; csrfToken: string }>(endpoint, {
            email,
            password,
            ...(customerToken ? { token: customerToken } : {}),
        });
        setUser(data.user);
        setCsrfToken(data.csrfToken);
    }, []);

    const registerCustomer = useCallback(async (input: { token: string; name: string; email: string; password: string }) => {
        const data = await apiPost<{ user: AppUser; csrfToken: string }>('/api/auth/customer/register', input);
        setUser(data.user);
        setCsrfToken(data.csrfToken);
    }, []);

    const logout = useCallback(async () => {
        await apiPost('/api/auth/logout', {}, csrfToken);
        setUser(null);
        setCsrfToken(null);
    }, [csrfToken]);

    const value = useMemo(() => ({ user, csrfToken, loading, refresh, login, registerCustomer, logout }), [user, csrfToken, loading, refresh, login, registerCustomer, logout]);

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
    const value = useContext(AuthContext);
    if (!value) throw new Error('useAuth must be used inside AuthProvider.');
    return value;
}
