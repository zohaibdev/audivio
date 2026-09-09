import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import * as adminApi from '../../api/admin';
import { apiErrorMessage } from '../../api/client';
import { Spinner } from '../../components/ui/Spinner';
import { ErrorState } from '../../components/ui/ErrorState';
import { EmptyState } from '../../components/ui/EmptyState';
import { Badge } from '../../components/ui/Badge';
import { Button } from '../../components/ui/Button';
import type { Lead } from '../../types';
import { SERVICE_NAMES, type ServiceKey } from '../../types';

type LeadDetail = Awaited<ReturnType<typeof adminApi.fetchAdminLead>>;

// Pipeline order, matching LeadStatus::pipeline() (§55). 'lost' sits last
// because it is an exit, not a step.
const statuses = ['new', 'contacted', 'qualified', 'proposal', 'won', 'lost'];
const statusTone: Record<string, 'gray' | 'blue' | 'yellow' | 'green' | 'red'> = {
  new: 'gray',
  contacted: 'blue',
  qualified: 'yellow',
  proposal: 'yellow',
  won: 'green',
  lost: 'red',
};

export function AdminLeadsPage() {
  const [leads, setLeads] = useState<Lead[] | null>(null);
  const [filter, setFilter] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [note, setNote] = useState<Record<number, string>>({});
  // Loaded per lead on demand. The list endpoint does not carry the
  // activity trail, and fetching every lead's history to render a list
  // nobody has expanded would be wasteful.
  const [detail, setDetail] = useState<Record<number, LeadDetail>>({});
  const [openId, setOpenId] = useState<number | null>(null);

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

  async function loadDetail(id: number) {
    const full = await adminApi.fetchAdminLead(id);
    setDetail((current) => ({ ...current, [id]: full }));
  }

  function toggle(id: number) {
    const next = openId === id ? null : id;
    setOpenId(next);
    if (next !== null && !detail[next]) void loadDetail(next);
  }

  async function addNote(id: number) {
    const text = note[id];
    if (!text) return;
    await adminApi.addLeadNote(id, text);
    setNote({ ...note, [id]: '' });

    // Previously the note was posted and nothing changed on screen —
    // the trail was never rendered anywhere, so a salesperson had no way
    // to tell a saved note from a lost one (§56).
    setOpenId(id);
    await loadDetail(id);
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold text-ink">Leads</h1>
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
            <div key={lead.id} className="rounded-xl border border-line bg-white p-5 dark:border-line dark:bg-slate-900">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <p className="font-medium text-ink">{lead.name} · {lead.email}</p>
                  <p className="text-xs text-faint">{lead.source} · {new Date(lead.created_at).toLocaleDateString()}</p>
                  {lead.message && <p className="mt-2 text-sm text-muted">{lead.message}</p>}
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
                <Button variant="secondary" onClick={() => toggle(lead.id)}>
                  {openId === lead.id ? 'Hide details' : 'Details'}
                </Button>
              </div>

              {openId === lead.id && <LeadDetailPanel detail={detail[lead.id]} />}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

/**
 * What a salesperson needs before the first call (§55): which site, which
 * audit, what they asked for, what they were looking at — and every note
 * anyone has left. All of it was already stored and none of it was shown.
 */
function LeadDetailPanel({ detail }: { detail?: LeadDetail }) {
  if (!detail) {
    return <p className="mt-3 text-xs text-faint">Loading details…</p>;
  }

  const site = detail.website?.primary_url ?? detail.website_url;

  return (
    <div className="mt-3 border-t border-slate-100 pt-3 dark:border-line">
      <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
        <div>
          <dt className="text-xs uppercase tracking-wide text-faint">Website</dt>
          <dd className="text-ink">
            {site ? (
              <a href={site} target="_blank" rel="noreferrer noopener" className="text-indigo-600 hover:underline dark:text-indigo-400">
                {site}
              </a>
            ) : (
              <span className="text-faint">Not associated</span>
            )}
          </dd>
        </div>

        <div>
          <dt className="text-xs uppercase tracking-wide text-faint">Audit</dt>
          <dd className="text-ink">
            {detail.audit_id ? (
              <Link to={`/audits/${detail.audit_id}/report`} className="text-indigo-600 hover:underline dark:text-indigo-400">
                Report #{detail.audit_id}
              </Link>
            ) : (
              <span className="text-faint">No audit</span>
            )}
          </dd>
        </div>

        <div>
          <dt className="text-xs uppercase tracking-wide text-faint">Wants help with</dt>
          <dd className="text-ink">
            {detail.requested_services?.length
              ? detail.requested_services
                  .map((key) => SERVICE_NAMES[key as ServiceKey] ?? key)
                  .join(', ')
              : <span className="text-faint">Not specified</span>}
          </dd>
        </div>

        <div>
          <dt className="text-xs uppercase tracking-wide text-faint">Findings referenced</dt>
          <dd className="text-ink">
            {/* Already filtered against the audit server-side, so these are
                findings the report really contains (§55). */}
            {detail.issue_codes?.length
              ? `${detail.issue_codes.length} from the report`
              : <span className="text-faint">None</span>}
          </dd>
        </div>
      </dl>

      {detail.issue_codes && detail.issue_codes.length > 0 && (
        <ul className="mt-2 flex flex-wrap gap-1">
          {detail.issue_codes.map((code) => (
            <li key={code} className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">
              {code}
            </li>
          ))}
        </ul>
      )}

      <h4 className="mt-4 text-xs font-semibold uppercase tracking-wide text-faint">Activity</h4>
      {detail.activities.length === 0 ? (
        <p className="mt-1 text-xs text-faint">Nothing recorded yet.</p>
      ) : (
        <ul className="mt-2 flex flex-col gap-2">
          {detail.activities.map((activity) => (
            <li key={activity.id} className="text-sm text-muted">
              {activity.note}
              <span className="ml-2 text-xs text-faint">
                {activity.user?.name ?? 'System'} · {new Date(activity.created_at).toLocaleString()}
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
