import { useCallback, useEffect, useState } from 'react';
import { createAioPrompt, deleteAioPrompt, fetchAioPrompts } from '../../api/websites';
import type { AioPromptSettings } from '../../api/websites';
import { apiErrorMessage } from '../../api/client';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { Card } from '../ui/Card';
import { Spinner } from '../ui/Spinner';

/**
 * The AI search questions asked of this site: the ten that always run,
 * plus the owner's own.
 *
 * The defaults are shown even though they are not editable — an empty
 * custom list would otherwise read as "nothing is being asked", which is
 * the opposite of true.
 */
export function AioPromptsSection({ websiteId }: { websiteId: number }) {
  const [settings, setSettings] = useState<AioPromptSettings | null>(null);
  const [draft, setDraft] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    setError(null);
    fetchAioPrompts(websiteId)
      .then(setSettings)
      .catch((err) => setError(apiErrorMessage(err)));
  }, [websiteId]);

  useEffect(load, [load]);

  async function add(event: React.FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await createAioPrompt(websiteId, draft.trim());
      setDraft('');
      load();
    } catch (err) {
      setError(apiErrorMessage(err));
    } finally {
      setBusy(false);
    }
  }

  async function remove(id: number) {
    setBusy(true);
    setError(null);
    try {
      await deleteAioPrompt(websiteId, id);
      load();
    } catch (err) {
      setError(apiErrorMessage(err));
    } finally {
      setBusy(false);
    }
  }

  if (error && !settings) {
    return (
      <Card>
        <p className="text-sm text-red-600 dark:text-red-400">{error}</p>
      </Card>
    );
  }

  if (!settings) return <Spinner />;

  const skipped = new Set(settings.context.skipped);

  return (
    <Card>
      <h3 className="text-sm font-semibold text-ink">
        Always asked ({settings.defaults.length})
      </h3>
      <ul className="mt-2 space-y-2">
        {settings.defaults.map((prompt) => (
          <li key={prompt.key} className="flex items-start gap-2 text-sm">
            <span className="text-muted">{prompt.question}</span>
            {skipped.has(prompt.key) && (
              // Skipped, not failed — the profile has nothing for this
              // check to measure the content against.
              <Badge tone="yellow">Needs your AI search goal</Badge>
            )}
          </li>
        ))}
      </ul>

      <h3 className="mt-6 text-sm font-semibold text-ink">
        Your own questions{' '}
        <span className="font-normal text-faint">
          ({settings.prompts.length}
          {settings.limit === null ? '' : ` of ${settings.limit}`})
        </span>
      </h3>

      {settings.prompts.length === 0 ? (
        <p className="mt-2 text-sm text-muted">
          None yet. Add a question you want asked of every audit of this site.
        </p>
      ) : (
        <ul className="mt-2 space-y-2">
          {settings.prompts.map((prompt) => (
            <li key={prompt.id} className="flex items-start justify-between gap-3 text-sm">
              <span className="text-muted">{prompt.prompt}</span>
              <button
                type="button"
                onClick={() => remove(prompt.id)}
                disabled={busy}
                className="shrink-0 text-xs font-medium text-red-600 hover:underline disabled:opacity-50 dark:text-red-400"
              >
                Remove
              </button>
            </li>
          ))}
        </ul>
      )}

      {settings.can_add ? (
        <form onSubmit={add} className="mt-4 flex flex-col gap-2 sm:flex-row">
          <input
            type="text"
            value={draft}
            minLength={10}
            maxLength={1000}
            required
            placeholder="e.g. Do we clearly explain our delivery times?"
            onChange={(event) => setDraft(event.target.value)}
            className="flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white"
          />
          <Button type="submit" isLoading={busy}>
            Add question
          </Button>
        </form>
      ) : (
        <p className="mt-4 text-sm text-muted">
          {settings.limit === 0
            ? 'Custom questions are not included in your current plan.'
            : `Your plan includes ${settings.limit} custom question${settings.limit === 1 ? '' : 's'}. Remove one to add another.`}
        </p>
      )}

      {error && (
        <p className="mt-2 text-sm text-red-600 dark:text-red-400" role="alert">
          {error}
        </p>
      )}
    </Card>
  );
}
