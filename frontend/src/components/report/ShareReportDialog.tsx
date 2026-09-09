import { useEffect, useState } from 'react';
import { Card } from '../ui/Card';
import { Badge } from '../ui/Badge';
import { apiErrorMessage } from '../../api/client';
import {
  createShare,
  fetchShares,
  regenerateShare,
  revokeShare,
  fetchShareEvents,
  updateShare,
} from '../../api/shares';
import type { ShareEvent, ShareStatus } from '../../api/shares';

/**
 * The owner's controls for sharing a report (§50).
 *
 * The URL is shown **once**, in the response that created it, and the
 * panel says so plainly. Nothing can recover it afterwards — only a hash
 * is stored — so a UI that implied otherwise would be lying about what
 * the system can do.
 */
export function ShareReportDialog({ auditId, onClose }: { auditId: number; onClose: () => void }) {
  const [shares, setShares] = useState<ShareStatus[] | null>(null);
  const [available, setAvailable] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const [password, setPassword] = useState('');
  const [allowPdf, setAllowPdf] = useState(false);
  const [allowScreenshots, setAllowScreenshots] = useState(true);
  const [allowRecommendations, setAllowRecommendations] = useState(true);

  /** Held in memory only, and only until this panel closes. */
  const [freshUrl, setFreshUrl] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);
  // §50 records six event types per share; until §56 nothing displayed
  // them, which makes an audit log that exists but cannot be read.
  const [events, setEvents] = useState<Record<number, ShareEvent[]>>({});
  const [openEvents, setOpenEvents] = useState<number | null>(null);

  useEffect(() => {
    fetchShares(auditId)
      .then((data) => {
        setShares(data.shares);
        setAvailable(data.is_available_on_plan);
      })
      .catch((caught) => setError(apiErrorMessage(caught)));
  }, [auditId]);

  async function reload() {
    const data = await fetchShares(auditId);
    setShares(data.shares);
  }

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      const created = await createShare(auditId, {
        password,
        allow_pdf: allowPdf,
        allow_screenshots: allowScreenshots,
        allow_recommendations: allowRecommendations,
      });

      setFreshUrl(created.url);
      setPassword('');
      await reload();
    } catch (caught) {
      setError(apiErrorMessage(caught));
    } finally {
      setBusy(false);
    }
  }

  async function act(action: () => Promise<unknown>) {
    setBusy(true);
    setError(null);

    try {
      await action();
      await reload();
    } catch (caught) {
      setError(apiErrorMessage(caught));
    } finally {
      setBusy(false);
    }
  }

  async function toggleEvents(shareId: number) {
    const next = openEvents === shareId ? null : shareId;
    setOpenEvents(next);

    if (next !== null && !events[next]) {
      const result = await fetchShareEvents(auditId, next);
      setEvents((current) => ({ ...current, [next]: result.events }));
    }
  }

  async function copy(url: string) {
    try {
      await navigator.clipboard.writeText(url);
      setCopied(true);
    } catch {
      // Clipboard access can be refused; the URL is selectable on screen.
      setCopied(false);
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4">
      <div className="mt-10 w-full max-w-2xl">
        <Card>
          <div className="flex items-start justify-between gap-3">
            <div>
              <h2 className="text-lg font-semibold text-ink">Share this report</h2>
              <p className="mt-1 text-sm text-muted">
                Anyone with the link and the password can read it. No account needed.
              </p>
            </div>
            <button
              type="button"
              onClick={onClose}
              aria-label="Close"
              className="text-faint hover:text-slate-700 dark:hover:text-slate-200"
            >
              ✕
            </button>
          </div>

          {!available && (
            <div className="mt-4 rounded-md border border-amber-300 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950/40">
              <p className="text-sm text-amber-900 dark:text-amber-200">
                Report sharing is not included in your current plan.
              </p>
            </div>
          )}

          {freshUrl && (
            <div className="mt-4 rounded-md border border-emerald-300 bg-emerald-50 p-3 dark:border-emerald-800 dark:bg-emerald-950/40">
              <p className="text-sm font-medium text-emerald-900 dark:text-emerald-200">
                Copy this link now — it is not shown again.
              </p>
              {/* Stated because it is true: only a hash of the token is
                  stored, so nothing can display this URL a second time. */}
              <div className="mt-2 flex gap-2">
                <input
                  readOnly
                  value={freshUrl}
                  onFocus={(event) => event.currentTarget.select()}
                  className="flex-1 rounded-md border border-emerald-300 bg-white px-3 py-2 font-mono text-xs dark:border-emerald-800 dark:bg-slate-900 dark:text-white"
                />
                <button
                  type="button"
                  onClick={() => copy(freshUrl)}
                  className="rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-500"
                >
                  {copied ? 'Copied' : 'Copy'}
                </button>
              </div>
              <p className="mt-2 text-xs text-emerald-800 dark:text-emerald-300">
                Send the password separately — a link and its password in the same message protect nothing.
              </p>
            </div>
          )}

          {error && <p className="mt-4 text-sm text-red-600 dark:text-red-400">{error}</p>}

          <form onSubmit={submit} className="mt-5 flex flex-col gap-4 border-t border-line pt-5 dark:border-line">
            <label className="text-sm">
              <span className="text-muted">Password (required)</span>
              <input
                required
                minLength={8}
                type="password"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                disabled={!available}
                className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:opacity-50 dark:border-slate-700 dark:bg-slate-800 dark:text-white"
              />
            </label>

            <fieldset className="flex flex-col gap-2">
              <legend className="text-sm text-muted">What the recipient can do</legend>
              <Toggle label="View screenshots" checked={allowScreenshots} onChange={setAllowScreenshots} />
              <Toggle label="View recommendations" checked={allowRecommendations} onChange={setAllowRecommendations} />
              <Toggle label="Download PDF" checked={allowPdf} onChange={setAllowPdf} />
            </fieldset>

            <button
              type="submit"
              disabled={busy || !available}
              className="self-start rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60"
            >
              {busy ? 'Creating…' : 'Create share link'}
            </button>
          </form>

          {shares && shares.length > 0 && (
            <div className="mt-6 border-t border-line pt-5 dark:border-line">
              <h3 className="font-medium text-ink">Existing links</h3>
              <ul className="mt-3 space-y-3">
                {shares.map((share) => (
                  <li key={share.id} className="rounded-md border border-line p-3 dark:border-line">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <div className="flex items-center gap-2">
                        {share.is_active ? <Badge tone="green">Active</Badge> : <Badge tone="gray">Inactive</Badge>}
                        <span className="text-xs text-muted">
                          {share.inactive_reason ??
                            (share.last_viewed_at
                              ? `Opened ${share.view_count} time${share.view_count === 1 ? '' : 's'}`
                              : 'Not opened yet')}
                        </span>
                      </div>

                      <div className="flex gap-2">
                        <button
                          type="button"
                          disabled={busy}
                          onClick={() =>
                            act(async () => {
                              const result = await regenerateShare(auditId, share.id);
                              setFreshUrl(result.url);
                            })
                          }
                          className="text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400"
                        >
                          New link
                        </button>
                        {share.is_active && (
                          <button
                            type="button"
                            disabled={busy}
                            onClick={() => act(() => revokeShare(auditId, share.id))}
                            className="text-xs font-medium text-red-600 hover:underline dark:text-red-400"
                          >
                            Revoke
                          </button>
                        )}
                        <button
                          type="button"
                          onClick={() => void toggleEvents(share.id)}
                          className="text-xs font-medium text-slate-600 hover:underline dark:text-slate-300"
                        >
                          {openEvents === share.id ? 'Hide activity' : 'Activity'}
                        </button>
                      </div>
                    </div>

                    {openEvents === share.id && <ShareEventLog events={events[share.id]} />}

                    {share.failed_attempts > 0 && (
                      // Surfaced rather than buried: a run of these is how
                      // an owner learns a link reached the wrong person.
                      <p className="mt-2 text-xs text-amber-700 dark:text-amber-400">
                        {share.failed_attempts} incorrect password attempt
                        {share.failed_attempts === 1 ? '' : 's'}
                      </p>
                    )}

                    <div className="mt-2 flex flex-wrap gap-3 text-xs text-muted">
                      <span>{share.allow_screenshots ? 'Screenshots' : 'No screenshots'}</span>
                      <span>{share.allow_recommendations ? 'Recommendations' : 'No recommendations'}</span>
                      <span>{share.allow_pdf ? 'PDF allowed' : 'No PDF'}</span>
                    </div>

                    <ChangePassword
                      busy={busy}
                      onSubmit={(next) => act(() => updateShare(auditId, share.id, { password: next }))}
                    />
                  </li>
                ))}
              </ul>
            </div>
          )}
        </Card>
      </div>
    </div>
  );
}

function Toggle({
  label,
  checked,
  onChange,
}: {
  label: string;
  checked: boolean;
  onChange: (value: boolean) => void;
}) {
  return (
    <label className="flex items-center gap-2 text-sm text-muted">
      <input type="checkbox" checked={checked} onChange={(event) => onChange(event.target.checked)} />
      {label}
    </label>
  );
}

function ChangePassword({
  busy,
  onSubmit,
}: {
  busy: boolean;
  onSubmit: (password: string) => void;
}) {
  const [open, setOpen] = useState(false);
  const [value, setValue] = useState('');

  if (!open) {
    return (
      <button
        type="button"
        onClick={() => setOpen(true)}
        className="mt-2 text-xs font-medium text-muted hover:text-slate-800 dark:text-faint dark:hover:text-slate-200"
      >
        Change password
      </button>
    );
  }

  return (
    <div className="mt-2 flex gap-2">
      <input
        type="password"
        minLength={8}
        value={value}
        placeholder="New password"
        onChange={(event) => setValue(event.target.value)}
        className="flex-1 rounded-md border border-slate-300 px-2 py-1 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
      />
      <button
        type="button"
        disabled={busy || value.length < 8}
        onClick={() => {
          onSubmit(value);
          setValue('');
          setOpen(false);
        }}
        className="rounded-md bg-slate-900 px-3 py-1 text-xs font-medium text-white disabled:opacity-50 dark:bg-white dark:text-slate-900"
      >
        Save
      </button>
    </div>
  );
}

/**
 * Who opened this link, when, and from where (§50).
 *
 * The distinction that matters is `is_recipient_action`: an owner
 * revoking their own link and a stranger failing a password are both
 * events, and reading them as the same thing is how a real intrusion
 * gets lost in routine noise.
 */
function ShareEventLog({ events }: { events?: ShareEvent[] }) {
  if (!events) {
    return <p className="mt-2 text-xs text-faint">Loading activity…</p>;
  }

  if (events.length === 0) {
    return <p className="mt-2 text-xs text-faint">Nothing has happened on this link yet.</p>;
  }

  return (
    <ul className="mt-2 space-y-1 border-t border-slate-100 pt-2 dark:border-line">
      {events.map((event, index) => (
        <li key={`${event.event}-${index}`} className="flex flex-wrap items-baseline gap-2 text-xs">
          <span
            className={
              event.is_recipient_action
                ? 'font-medium text-ink'
                : 'font-medium text-brand'
            }
          >
            {event.label}
          </span>
          <span className="text-faint">
            {event.created_at ? new Date(event.created_at).toLocaleString() : 'unknown time'}
            {event.ip_address ? ` · ${event.ip_address}` : ''}
          </span>
        </li>
      ))}
    </ul>
  );
}
