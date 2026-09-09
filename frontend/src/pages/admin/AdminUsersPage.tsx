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
      <h1 className="mb-6 text-2xl font-bold text-ink">Users</h1>
      {users.data.length === 0 ? (
        <EmptyState title="No users yet" />
      ) : (
        <div className="overflow-x-auto rounded-xl border border-line">
          <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-line">
            <thead className="bg-surface-2">
              <tr>
                <th className="px-4 py-2 text-left font-medium text-muted">Name</th>
                <th className="px-4 py-2 text-left font-medium text-muted">Email</th>
                <th className="px-4 py-2 text-left font-medium text-muted">Plan</th>
                <th className="px-4 py-2 text-left font-medium text-muted">Role</th>
                <th className="px-4 py-2 text-left font-medium text-muted">Joined</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-line bg-white dark:divide-line dark:bg-slate-900">
              {users.data.map((user) => (
                <tr key={user.id}>
                  <td className="px-4 py-2 text-muted">{user.name}</td>
                  <td className="px-4 py-2 text-muted">{user.email}</td>
                  <td className="px-4 py-2 text-muted">{user.subscription?.plan?.name ?? '—'}</td>
                  <td className="px-4 py-2">{user.is_admin && <Badge tone="purple">Admin</Badge>}</td>
                  <td className="px-4 py-2 text-muted">{new Date(user.created_at).toLocaleDateString()}</td>
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
