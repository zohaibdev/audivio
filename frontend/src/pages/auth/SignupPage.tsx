import { useState } from 'react';
import type { FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../../contexts/AuthContext';
import { apiErrorMessage, ApiRequestError } from '../../api/client';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';

export function SignupPage() {
  const { register } = useAuth();
  const navigate = useNavigate();
  const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '' });
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [isSubmitting, setIsSubmitting] = useState(false);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setFieldErrors({});
    setIsSubmitting(true);
    try {
      await register(form.name, form.email, form.password, form.password_confirmation);
      navigate('/dashboard', { replace: true });
    } catch (err) {
      if (err instanceof ApiRequestError && err.errors) {
        setFieldErrors(err.errors);
      }
      setError(apiErrorMessage(err));
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <div className="mx-auto flex min-h-[70vh] max-w-sm flex-col justify-center px-4 py-16">
      <h1 className="text-2xl font-bold text-ink">Create your account</h1>
      <form onSubmit={handleSubmit} className="mt-6 flex flex-col gap-4">
        <Input
          label="Name"
          required
          value={form.name}
          onChange={(e) => setForm({ ...form, name: e.target.value })}
          error={fieldErrors.name?.[0]}
        />
        <Input
          label="Email"
          type="email"
          required
          autoComplete="email"
          value={form.email}
          onChange={(e) => setForm({ ...form, email: e.target.value })}
          error={fieldErrors.email?.[0]}
        />
        <Input
          label="Password"
          type="password"
          required
          autoComplete="new-password"
          value={form.password}
          onChange={(e) => setForm({ ...form, password: e.target.value })}
          error={fieldErrors.password?.[0]}
        />
        <Input
          label="Confirm password"
          type="password"
          required
          autoComplete="new-password"
          value={form.password_confirmation}
          onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })}
        />
        <p className="text-xs text-muted">
          Password must be at least 8 characters and include letters and numbers.
        </p>
        {error && (
          <p className="text-sm text-red-600 dark:text-red-400" role="alert">
            {error}
          </p>
        )}
        <Button type="submit" isLoading={isSubmitting}>
          Create account
        </Button>
      </form>
      <p className="mt-4 text-sm">
        Already have an account?{' '}
        <Link to="/login" className="text-indigo-600 hover:underline dark:text-indigo-400">
          Log in
        </Link>
      </p>
    </div>
  );
}
