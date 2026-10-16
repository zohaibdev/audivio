import { useCallback, useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { fetchMyWebsites, fetchWebsite } from '../../api/websites';
import type { MyWebsite, WebsiteDetail as WebsiteDetailData } from '../../api/websites';
import { apiErrorMessage } from '../../api/client';
import { Spinner } from '../../components/ui/Spinner';
import { ErrorState } from '../../components/ui/ErrorState';
import { EmptyState } from '../../components/ui/EmptyState';
import { AuditUrlForm } from '../../components/audit/AuditUrlForm';
import { WebsiteCard } from '../../components/website/WebsiteCard';
import { WebsiteDetail } from '../../components/website/WebsiteDetail';

/**
 * One page, two views: the card grid, and — when `?website=` names one of
 * the caller's domains — that website's detail.
 *
 * The query parameter selects which website to show. It is not an access
 * check: the backend resolves it against the authenticated user's own
 * websites, so a domain belonging to someone else 404s exactly as an
 * unknown one does.
 */
export function DashboardWebsitesPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const selected = searchParams.get('website');

  const [websites, setWebsites] = useState<MyWebsite[] | null>(null);
  const [detail, setDetail] = useState<WebsiteDetailData | null>(null);
  const [error, setError] = useState<string | null>(null);

  const loadList = useCallback(() => {
    setError(null);
    setWebsites(null);
    fetchMyWebsites()
      .then((page) => setWebsites(page.data))
      .catch((err) => setError(apiErrorMessage(err)));
  }, []);

  const loadDetail = useCallback((identifier: string) => {
    setError(null);
    setDetail(null);
    fetchWebsite(identifier)
      .then(setDetail)
      .catch((err) => setError(apiErrorMessage(err)));
  }, []);

  useEffect(() => {
    if (selected) {
      loadDetail(selected);
    } else {
      setDetail(null);
      loadList();
    }
  }, [selected, loadDetail, loadList]);

  if (selected) {
    return (
      <div>
        <button
          type="button"
          onClick={() => setSearchParams({})}
          className="mb-4 text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400"
        >
          ← All websites
        </button>
        {error && <ErrorState message={error} onRetry={() => loadDetail(selected)} />}
        {!error && !detail && <Spinner />}
        {detail && <WebsiteDetail detail={detail} onUpdated={setDetail} />}
      </div>
    );
  }

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold text-ink">My Websites</h1>
        <Link
          to="/audit"
          className="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400"
        >
          Run a new audit →
        </Link>
      </div>

      {error && <ErrorState message={error} onRetry={loadList} />}
      {!error && !websites && <Spinner />}
      {websites && websites.length === 0 && (
        <EmptyState
          title="No websites yet"
          description="Run your first audit to add a website."
          action={
            <div className="mt-4 w-full max-w-sm">
              <AuditUrlForm compact />
            </div>
          }
        />
      )}
      {websites && websites.length > 0 && (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          {websites.map((website) => (
            <WebsiteCard key={website.id} website={website} />
          ))}
        </div>
      )}
    </div>
  );
}
