import { useEffect, useState } from 'react';
import * as adminApi from '../../api/admin';
import { apiErrorMessage } from '../../api/client';
import { Spinner } from '../../components/ui/Spinner';
import { ErrorState } from '../../components/ui/ErrorState';
import { EmptyState } from '../../components/ui/EmptyState';
import { Badge } from '../../components/ui/Badge';
import { Pagination } from '../../components/ui/Pagination';
import type { Paginated, Plan, User } from '../../types';

export function AdminUsersPage() {
  const [users, setUsers] = useState<Paginated<User & { subscription?: { plan?: Plan } }> | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);

  function load() {
    setError(null);
    adminApi
      .fetchUsers(page)
      .then(setUsers)
      .catch((err) => setError(apiErrorMessage(err)));
  }

  useEffect(load, [page]);

  if (error) return <ErrorState message={error} onRetry={load} />;
  if (!users) return <Spinner />;

  return (
    <div>
      <h1 className="mb-6 text-2xl font-bold text-slate-900 dark:text-white">Users</h1>
      {users.data.length === 0 ? (
        <EmptyState title="No users yet" />
      ) : (
        <div className="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-800">
          <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
            <thead className="bg-slate-50 dark:bg-slate-800/50">
              <tr>
                <th className="px-4 py-2 text-left font-medium text-slate-500 dark:text-slate-400">Name</th>
                <th className="px-4 py-2 text-left font-medium text-slate-500 dark:text-slate-400">Email</th>
                <th className="px-4 py-2 text-left font-medium text-slate-500 dark:text-slate-400">Plan</th>
                <th className="px-4 py-2 text-left font-medium text-slate-500 dark:text-slate-400">Role</th>
                <th className="px-4 py-2 text-left font-medium text-slate-500 dark:text-slate-400">Joined</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 bg-white dark:divide-slate-800 dark:bg-slate-900">
              {users.data.map((user) => (
                <tr key={user.id}>
                  <td className="px-4 py-2 text-slate-700 dark:text-slate-300">{user.name}</td>
                  <td className="px-4 py-2 text-slate-500 dark:text-slate-400">{user.email}</td>
                  <td className="px-4 py-2 text-slate-500 dark:text-slate-400">{user.subscription?.plan?.name ?? '—'}</td>
                  <td className="px-4 py-2">{user.is_admin && <Badge tone="purple">Admin</Badge>}</td>
                  <td className="px-4 py-2 text-slate-500 dark:text-slate-400">{new Date(user.created_at).toLocaleDateString()}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <Pagination currentPage={users.current_page} lastPage={users.last_page} total={users.total} onChange={setPage} />
        </div>
      )}
    </div>
  );
}
