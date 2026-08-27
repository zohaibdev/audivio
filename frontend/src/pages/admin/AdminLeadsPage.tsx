import { useEffect, useState } from 'react';
import * as adminApi from '../../api/admin';
import { apiErrorMessage } from '../../api/client';
import { Spinner } from '../../components/ui/Spinner';
import { ErrorState } from '../../components/ui/ErrorState';
import { EmptyState } from '../../components/ui/EmptyState';
import { Badge } from '../../components/ui/Badge';
import { Button } from '../../components/ui/Button';
import type { Lead } from '../../types';

const statuses = ['new', 'contacted', 'qualified', 'converted', 'lost'];
const statusTone: Record<string, 'gray' | 'blue' | 'yellow' | 'green' | 'red'> = {
  new: 'gray',
  contacted: 'blue',
  qualified: 'yellow',
  converted: 'green',
  lost: 'red',
};

export function AdminLeadsPage() {
  const [leads, setLeads] = useState<Lead[] | null>(null);
  const [filter, setFilter] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [note, setNote] = useState<Record<number, string>>({});

  function load() {
    setError(null);
    adminApi
      .fetchAdminLeads(filter || undefined)
      .then((page) => setLeads(page.data))
      .catch((err) => setError(apiErrorMessage(err)));
  }

  useEffect(load, [filter]);

  async function updateStatus(id: number, status: string) {
    await adminApi.updateLeadStatus(id, status);
    load();
  }

  async function addNote(id: number) {
    const text = note[id];
    if (!text) return;
    await adminApi.addLeadNote(id, text);
    setNote({ ...note, [id]: '' });
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Leads</h1>
        <select
          value={filter}
          onChange={(e) => setFilter(e.target.value)}
          className="rounded-md border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
        >
          <option value="">All statuses</option>
          {statuses.map((s) => (
            <option key={s} value={s}>
              {s}
            </option>
          ))}
        </select>
      </div>

      {error && <ErrorState message={error} onRetry={load} />}
      {!error && !leads && <Spinner />}
      {leads && leads.length === 0 && <EmptyState title="No leads yet" />}

      {leads && leads.length > 0 && (
        <div className="flex flex-col gap-4">
          {leads.map((lead) => (
            <div key={lead.id} className="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <p className="font-medium text-slate-900 dark:text-white">{lead.name} · {lead.email}</p>
                  <p className="text-xs text-slate-400">{lead.source} · {new Date(lead.created_at).toLocaleDateString()}</p>
                  {lead.message && <p className="mt-2 text-sm text-slate-600 dark:text-slate-300">{lead.message}</p>}
                </div>
                <Badge tone={statusTone[lead.status] ?? 'gray'}>{lead.status}</Badge>
              </div>
              <div className="mt-3 flex flex-wrap items-center gap-2">
                <select
                  value={lead.status}
                  onChange={(e) => updateStatus(lead.id, e.target.value)}
                  className="rounded-md border border-slate-300 px-2 py-1 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                >
                  {statuses.map((s) => (
                    <option key={s} value={s}>
                      {s}
                    </option>
                  ))}
                </select>
                <input
                  type="text"
                  placeholder="Add a note…"
                  value={note[lead.id] ?? ''}
                  onChange={(e) => setNote({ ...note, [lead.id]: e.target.value })}
                  className="flex-1 rounded-md border border-slate-300 px-2 py-1 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                />
                <Button variant="secondary" onClick={() => addNote(lead.id)}>
                  Add note
                </Button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
