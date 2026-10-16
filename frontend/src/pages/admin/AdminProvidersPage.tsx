import { useEffect, useState } from 'react';
import * as adminApi from '../../api/admin';
import { apiErrorMessage } from '../../api/client';
import { ProviderCard } from '../../components/admin/ProviderCard';
import { Spinner } from '../../components/ui/Spinner';
import { ErrorState } from '../../components/ui/ErrorState';
import type { ApiProviderSummary } from '../../types';

export function AdminProvidersPage() {
  const [providers, setProviders] = useState<ApiProviderSummary[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  function load() {
    setError(null);
    adminApi
      .fetchProviders()
      .then(setProviders)
      .catch((err) => setError(apiErrorMessage(err)));
  }

  useEffect(load, []);

  if (error) return <ErrorState message={error} onRetry={load} />;
  if (!providers) return <Spinner label="Loading providers…" />;

  return (
    <div>
      <h1 className="mb-1 text-2xl font-bold text-ink">AI & API Providers</h1>
      <p className="mb-6 text-sm text-muted">
        Credentials are encrypted at rest and never shown in full — only a masked preview.
      </p>
      <div className="flex flex-col gap-6">
        {providers.map((provider) => (
          <ProviderCard key={provider.slug} provider={provider} onUpdated={load} />
        ))}
      </div>
    </div>
  );
}
