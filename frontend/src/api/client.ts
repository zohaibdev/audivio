import axios, { AxiosError } from 'axios';

export const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api',
  headers: {
    Accept: 'application/json',
  },
});

apiClient.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

export interface ApiSuccessResponse<T> {
  success: true;
  message?: string;
  data: T;
}

export interface ApiErrorResponse {
  success: false;
  message: string;
  errors?: Record<string, string[]>;
}

/** Normalized shape every API call in this app resolves to or throws. */
export class ApiRequestError extends Error {
  status: number;
  errors?: Record<string, string[]>;

  constructor(message: string, status: number, errors?: Record<string, string[]>) {
    super(message);
    this.status = status;
    this.errors = errors;
  }
}

export function apiErrorMessage(error: unknown): string {
  if (error instanceof ApiRequestError) {
    return error.message;
  }
  if (error instanceof Error) {
    return error.message;
  }
  return 'Something went wrong. Please try again.';
}

/**
 * Unwraps the backend's {success, data} envelope and rethrows any
 * failure as ApiRequestError, so callers never need to know axios
 * exists — this is the one place that translates transport errors into
 * app-level ones.
 */
export async function unwrap<T>(promise: Promise<{ data: ApiSuccessResponse<T> }>): Promise<T> {
  try {
    const response = await promise;
    return response.data.data;
  } catch (error) {
    if (error instanceof AxiosError) {
      const body = error.response?.data as ApiErrorResponse | undefined;
      throw new ApiRequestError(
        body?.message ?? error.message ?? 'Request failed.',
        error.response?.status ?? 0,
        body?.errors,
      );
    }
    throw error;
  }
}
