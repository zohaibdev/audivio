import { useState } from 'react';
import { submitLead } from '../../api/leads';
import { apiErrorMessage } from '../../api/client';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';

export function ContactPage() {
  const [form, setForm] = useState({ name: '', email: '', website_url: '', message: '' });
  const [source, setSource] = useState<'talk_to_consultant' | 'request_audit_review'>('talk_to_consultant');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [submitted, setSubmitted] = useState(false);

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    setIsSubmitting(true);
    try {
      await submitLead({ ...form, source });
      setSubmitted(true);
    } catch (err) {
      setError(apiErrorMessage(err));
    } finally {
      setIsSubmitting(false);
    }
  }

  if (submitted) {
    return (
      <div className="mx-auto max-w-md px-4 py-24 text-center">
        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Thanks — we'll be in touch.</h1>
        <p className="mt-2 text-sm text-slate-600 dark:text-slate-300">A member of our team will reach out shortly.</p>
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-md px-4 py-16">
      <h1 className="text-3xl font-bold text-slate-900 dark:text-white">Talk to a consultant</h1>
      <p className="mt-2 text-sm text-slate-600 dark:text-slate-300">
        Tell us a bit about what you need and we'll get back to you.
      </p>

      <form onSubmit={handleSubmit} className="mt-8 flex flex-col gap-4">
        <fieldset className="flex gap-4 text-sm text-slate-700 dark:text-slate-300">
          <legend className="mb-1 text-sm font-medium">I'd like to</legend>
          <label className="flex items-center gap-2">
            <input
              type="radio"
              name="source"
              checked={source === 'talk_to_consultant'}
              onChange={() => setSource('talk_to_consultant')}
            />
            Talk to a consultant
          </label>
          <label className="flex items-center gap-2">
            <input
              type="radio"
              name="source"
              checked={source === 'request_audit_review'}
              onChange={() => setSource('request_audit_review')}
            />
            Request an audit review
          </label>
        </fieldset>

        <Input
          label="Name"
          required
          value={form.name}
          onChange={(event) => setForm({ ...form, name: event.target.value })}
        />
        <Input
          label="Email"
          type="email"
          required
          value={form.email}
          onChange={(event) => setForm({ ...form, email: event.target.value })}
        />
        <Input
          label="Website URL (optional)"
          type="url"
          value={form.website_url}
          onChange={(event) => setForm({ ...form, website_url: event.target.value })}
        />
        <div className="flex flex-col gap-1">
          <label htmlFor="message" className="text-sm font-medium text-slate-700 dark:text-slate-300">
            Message (optional)
          </label>
          <textarea
            id="message"
            rows={4}
            value={form.message}
            onChange={(event) => setForm({ ...form, message: event.target.value })}
            className="rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white"
          />
        </div>

        {error && <p className="text-sm text-red-600 dark:text-red-400">{error}</p>}

        <Button type="submit" isLoading={isSubmitting}>
          Send message
        </Button>
      </form>
    </div>
  );
}
