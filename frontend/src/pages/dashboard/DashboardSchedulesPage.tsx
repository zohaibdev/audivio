import { useEffect, useState } from 'react';
import { fetchMyWebsites } from '../../api/websites';
import type { MyWebsite } from '../../api/websites';
import * as schedulesApi from '../../api/schedules';
import { apiErrorMessage } from '../../api/client';
import { Card } from '../../components/ui/Card';
import { Button } from '../../components/ui/Button';
import { Badge } from '../../components/ui/Badge';
import { Spinner } from '../../components/ui/Spinner';
import { ErrorState } from '../../components/ui/ErrorState';
import { EmptyState } from '../../components/ui/EmptyState';
import type { AuditSchedule, ScheduleFrequency } from '../../types';

export function DashboardSchedulesPage() {
  const [schedules, setSchedules] = useState<AuditSchedule[] | null>(null);
  const [websites, setWebsites] = useState<MyWebsite[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [selectedWebsite, setSelectedWebsite] = useState('');
  const [frequency, setFrequency] = useState<ScheduleFrequency>('weekly');
  const [isSubmitting, setIsSubmitting] = useState(false);

  function load() {
    setError(null);
    Promise.all([schedulesApi.fetchSchedules(), fetchMyWebsites()])
      .then(([scheduleList, websitesPage]) => {
        setSchedules(scheduleList);
        setWebsites(websitesPage.data);
      })
      .catch((err) => setError(apiErrorMessage(err)));
  }

  useEffect(load, []);

  async function handleCreate(event: React.FormEvent) {
    event.preventDefault();
    setFormError(null);
    if (!selectedWebsite) {
      setFormError('Choose a website first.');
      return;
    }
    setIsSubmitting(true);
    try {
      await schedulesApi.createSchedule({ website_id: Number(selectedWebsite), frequency });
      load();
      setSelectedWebsite('');
    } catch (err) {
      setFormError(apiErrorMessage(err));
    } finally {
      setIsSubmitting(false);
    }
  }

  async function toggleActive(schedule: AuditSchedule) {
    await schedulesApi.updateSchedule(schedule.id, { is_active: !schedule.is_active });
    load();
  }

  async function remove(schedule: AuditSchedule) {
    await schedulesApi.deleteSchedule(schedule.id);
    load();
  }

  if (error) return <ErrorState message={error} onRetry={load} />;
  if (!schedules || !websites) return <Spinner />;

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-bold text-ink">Scheduled Audits</h1>
        <p className="mt-1 text-sm text-muted">
          Recurring audits require a plan with the scheduled-audits feature enabled.
        </p>
      </div>

      <Card>
        <form onSubmit={handleCreate} className="flex flex-col gap-3 sm:flex-row sm:items-end">
          <div className="flex flex-1 flex-col gap-1">
            <label htmlFor="schedule-website" className="text-sm font-medium text-muted">
              Website
            </label>
            <select
              id="schedule-website"
              value={selectedWebsite}
              onChange={(e) => setSelectedWebsite(e.target.value)}
              className="rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
            >
              <option value="">Select a website…</option>
              {websites.map((website) => (
                <option key={website.id} value={website.id}>
                  {website.domain}
                </option>
              ))}
            </select>
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="schedule-frequency" className="text-sm font-medium text-muted">
              Frequency
            </label>
            <select
              id="schedule-frequency"
              value={frequency}
              onChange={(e) => setFrequency(e.target.value as ScheduleFrequency)}
              className="rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
            >
              <option value="daily">Daily</option>
              <option value="weekly">Weekly</option>
              <option value="monthly">Monthly</option>
            </select>
          </div>
          <Button type="submit" isLoading={isSubmitting}>
            Add schedule
          </Button>
        </form>
        {formError && <p className="mt-2 text-sm text-red-600 dark:text-red-400">{formError}</p>}
      </Card>

      {schedules.length === 0 ? (
        <EmptyState title="No scheduled audits yet" description="Add one above to start tracking a website automatically." />
      ) : (
        <div className="flex flex-col gap-3">
          {schedules.map((schedule) => (
            <Card key={schedule.id} className="flex items-center justify-between">
              <div>
                <p className="font-medium text-ink">{schedule.website?.domain ?? `Website #${schedule.website_id}`}</p>
                <p className="text-xs text-faint">
                  {schedule.frequency} · next run {new Date(schedule.next_run_at).toLocaleDateString()}
                </p>
              </div>
              <div className="flex items-center gap-3">
                <Badge tone={schedule.is_active ? 'green' : 'gray'}>{schedule.is_active ? 'Active' : 'Paused'}</Badge>
                <Button variant="secondary" onClick={() => toggleActive(schedule)}>
                  {schedule.is_active ? 'Pause' : 'Resume'}
                </Button>
                <Button variant="danger" onClick={() => remove(schedule)}>
                  Remove
                </Button>
              </div>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
