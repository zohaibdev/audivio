import { Link } from 'react-router-dom';

/**
 * Self-service password reset isn't built on the backend yet (no email
 * infrastructure has been decided — see CONTEXT.md §9/§29) — this page
 * is intentionally honest about that rather than pretending to submit a
 * reset request to an endpoint that doesn't exist.
 */
export function ForgotPasswordPage() {
  return (
    <div className="mx-auto flex min-h-[70vh] max-w-sm flex-col justify-center px-4 py-16 text-center">
      <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Forgot your password?</h1>
      <p className="mt-4 text-sm text-slate-600 dark:text-slate-300">
        Self-service password reset isn't available yet. Please{' '}
        <Link to="/contact" className="text-indigo-600 hover:underline dark:text-indigo-400">
          contact us
        </Link>{' '}
        and we'll help you regain access to your account.
      </p>
      <Link to="/login" className="mt-6 text-sm text-indigo-600 hover:underline dark:text-indigo-400">
        Back to log in
      </Link>
    </div>
  );
}
