import { useState } from 'react';
import type { FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { createAudit } from '../../api/audits';
import { apiErrorMessage } from '../../api/client';
import { Button } from '../ui/Button';

export function AuditUrlForm({ compact = false }: { compact?: boolean }) {
  const navigate = useNavigate();
  const [url, setUrl] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  function isLikelyUrl(value: string): boolean {
    try {
      const parsed = new URL(value.includes('://') ? value : `https://${value}`);
      return Boolean(parsed.hostname);
    } catch {
      return false;
    }
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);

    if (!isLikelyUrl(url)) {
      setError('Enter a valid website address, e.g. https://example.com');
      return;
    }

    setIsSubmitting(true);
    try {
      const normalized = url.includes('://') ? url : `https://${url}`;
      const result = await createAudit(normalized);
      navigate(`/audits/${result.audit_id}`);
    } catch (err) {
      setError(apiErrorMessage(err));
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className={compact ? 'flex flex-col gap-3' : 'mx-auto flex max-w-xl flex-col gap-3 sm:flex-row'}>
      <label htmlFor="audit-url" className="sr-only">
        Website URL
      </label>
      <input
        id="audit-url"
        type="text"
        required
        placeholder="https://yourwebsite.com"
        value={url}
        onChange={(event) => setUrl(event.target.value)}
        className="flex-1 rounded-md border border-slate-300 px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white"
      />
      <Button type="submit" isLoading={isSubmitting} className="px-6 py-3">
        Run audit
      </Button>
      {error && (
        <p className="text-sm text-red-600 dark:text-red-400 sm:basis-full" role="alert">
          {error}
        </p>
      )}
    </form>
  );
}
