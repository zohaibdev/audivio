import { apiClient, unwrap } from './client';
import type { User } from '../types';

export interface AuthResult {
  user: User;
  token: string;
}

export function register(payload: { name: string; email: string; password: string; password_confirmation: string }) {
  return unwrap<AuthResult>(apiClient.post('/register', payload));
}

export function login(payload: { email: string; password: string }) {
  return unwrap<AuthResult>(apiClient.post('/login', payload));
}

export function logout() {
  return unwrap<null>(apiClient.post('/logout'));
}

export function fetchCurrentUser() {
  return unwrap<User>(apiClient.get('/user'));
}
