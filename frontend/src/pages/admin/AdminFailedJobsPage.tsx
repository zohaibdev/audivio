import { useEffect, useState } from 'react';
import * as adminApi from '../../api/admin';
import { apiErrorMessage } from '../../api/client';
import { Button } from '../../components/ui/Button';
import { Spinner } from '../../components/ui/Spinner';
import { ErrorState } from '../../components/ui/ErrorState';
import { EmptyState } from '../../components/ui/EmptyState';
import { Pagination } from '../../components/ui/Pagination';
import type { Paginated } from '../../types';

interface FailedJob {
  id: number;
  connection: string;
  queue: string;
  exception: string;
  failed_at: string;
}

export function AdminFailedJobsPage() {
  const [jobs, setJobs] = useState<Paginated<FailedJob> | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);

  function load() {
    setError(null);
    adminApi
      .fetchFailedJobs(page)
      .then(setJobs)
      .catch((err) => setError(apiErrorMessage(err)));
  }

  useEffect(load, [page]);

  async function handleDelete(id: number) {
    await adminApi.deleteFailedJob(id);
    load();
  }

  if (error) return <ErrorState message={error} onRetry={load} />;
  if (!jobs) return <Spinner />;

  return (
    <div>
      <h1 className="mb-1 text-2xl font-bold text-slate-900 dark:text-white">Failed Jobs</h1>
      <p className="mb-6 text-sm text-slate-500 dark:text-slate-400">List and remove only — retrying a failed job isn't supported yet.</p>

      {jobs.data.length === 0 ? (
        <EmptyState title="No failed jobs" description="Everything is running smoothly." />
      ) : (
        <div className="flex flex-col gap-3">
          {jobs.data.map((job) => (
            <div key={job.id} className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
              <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                  <p className="text-sm font-medium text-slate-800 dark:text-slate-200">
                    {job.connection} / {job.queue}
                  </p>
                  <p className="mt-1 truncate text-xs text-red-500">{job.exception}</p>
                  <p className="mt-1 text-xs text-slate-400">{new Date(job.failed_at).toLocaleString()}</p>
                </div>
                <Button variant="danger" onClick={() => handleDelete(job.id)}>
                  Delete
                </Button>
              </div>
            </div>
          ))}
          <Pagination currentPage={jobs.current_page} lastPage={jobs.last_page} total={jobs.total} onChange={setPage} />
        </div>
      )}
    </div>
  );
}
