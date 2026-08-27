import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import * as authApi from '../api/auth';
import type { User } from '../types';

interface AuthContextValue {
  user: User | null;
  isLoading: boolean;
  login: (email: string, password: string) => Promise<void>;
  register: (name: string, email: string, password: string, passwordConfirmation: string) => Promise<void>;
  logout: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    const token = localStorage.getItem('auth_token');
    if (!token) {
      setIsLoading(false);
      return;
    }
    authApi
      .fetchCurrentUser()
      .then(setUser)
      .catch(() => {
        localStorage.removeItem('auth_token');
      })
      .finally(() => setIsLoading(false));
  }, []);

  const login = useCallback(async (email: string, password: string) => {
    const result = await authApi.login({ email, password });
    localStorage.setItem('auth_token', result.token);
    setUser(result.user);
  }, []);

  const register = useCallback(async (name: string, email: string, password: string, passwordConfirmation: string) => {
    const result = await authApi.register({ name, email, password, password_confirmation: passwordConfirmation });
    localStorage.setItem('auth_token', result.token);
    setUser(result.user);
  }, []);

  const logout = useCallback(async () => {
    try {
      await authApi.logout();
    } finally {
      localStorage.removeItem('auth_token');
      setUser(null);
    }
  }, []);

  const value = useMemo(() => ({ user, isLoading, login, register, logout }), [user, isLoading, login, register, logout]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
}
