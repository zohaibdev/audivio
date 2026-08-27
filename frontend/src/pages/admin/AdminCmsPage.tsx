import { useEffect, useState } from 'react';
import * as adminApi from '../../api/admin';
import { apiErrorMessage } from '../../api/client';
import { Card } from '../../components/ui/Card';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { Spinner } from '../../components/ui/Spinner';
import { ErrorState } from '../../components/ui/ErrorState';
import { EmptyState } from '../../components/ui/EmptyState';
import type { CmsPage } from '../../types';

function SectionEditor({ page, onSaved }: { page: CmsPage; onSaved: () => void }) {
  const [sectionKey, setSectionKey] = useState('');
  const [contentJson, setContentJson] = useState('{\n  \n}');
  const [error, setError] = useState<string | null>(null);
  const [isSaving, setIsSaving] = useState(false);

  async function handleSave(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    if (!sectionKey) {
      setError('Section key is required.');
      return;
    }
    let content: Record<string, unknown>;
    try {
      content = JSON.parse(contentJson);
    } catch {
      setError('Content must be valid JSON.');
      return;
    }
    setIsSaving(true);
    try {
      await adminApi.putCmsSection(page.id, sectionKey, content);
      setSectionKey('');
      setContentJson('{\n  \n}');
      onSaved();
    } catch (err) {
      setError(apiErrorMessage(err));
    } finally {
      setIsSaving(false);
    }
  }

  async function handleDelete(key: string) {
    await adminApi.deleteCmsSection(page.id, key);
    onSaved();
  }

  return (
    <div className="mt-4 border-t border-slate-100 pt-4 dark:border-slate-800">
      <h3 className="mb-2 text-sm font-semibold text-slate-700 dark:text-slate-200">Sections</h3>
      {page.sections.length === 0 ? (
        <p className="mb-3 text-sm text-slate-500 dark:text-slate-400">No sections yet.</p>
      ) : (
        <ul className="mb-4 space-y-2">
          {page.sections.map((section) => (
            <li key={section.id} className="flex items-center justify-between rounded-md bg-slate-50 px-3 py-2 text-sm dark:bg-slate-800/50">
              <div>
                <span className="font-medium text-slate-800 dark:text-slate-200">{section.section_key}</span>
                <pre className="mt-1 max-w-md overflow-x-auto text-xs text-slate-500 dark:text-slate-400">{JSON.stringify(section.content)}</pre>
              </div>
              <Button variant="danger" onClick={() => handleDelete(section.section_key)}>
                Delete
              </Button>
            </li>
          ))}
        </ul>
      )}

      <form onSubmit={handleSave} className="flex flex-col gap-3">
        <Input label="Section key" value={sectionKey} onChange={(e) => setSectionKey(e.target.value)} placeholder="hero, faq, pricing…" />
        <div>
          <label htmlFor={`content-${page.id}`} className="text-sm font-medium text-slate-700 dark:text-slate-300">
            Content (JSON)
          </label>
          <textarea
            id={`content-${page.id}`}
            rows={4}
            value={contentJson}
            onChange={(e) => setContentJson(e.target.value)}
            className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
          />
        </div>
        {error && <p className="text-sm text-red-600 dark:text-red-400">{error}</p>}
        <Button type="submit" isLoading={isSaving} className="self-start">
          Save section
        </Button>
      </form>
    </div>
  );
}

export function AdminCmsPage() {
  const [pages, setPages] = useState<CmsPage[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [newSlug, setNewSlug] = useState('');
  const [newTitle, setNewTitle] = useState('');
  const [createError, setCreateError] = useState<string | null>(null);

  function load() {
    setError(null);
    adminApi
      .fetchAdminCmsPages()
      .then(setPages)
      .catch((err) => setError(apiErrorMessage(err)));
  }

  useEffect(load, []);

  async function handleCreate(event: React.FormEvent) {
    event.preventDefault();
    setCreateError(null);
    try {
      await adminApi.createCmsPage({ slug: newSlug, title: newTitle });
      setNewSlug('');
      setNewTitle('');
      load();
    } catch (err) {
      setCreateError(apiErrorMessage(err));
    }
  }

  async function handleDeletePage(id: number) {
    await adminApi.deleteCmsPage(id);
    load();
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">CMS</h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
          Manage every dynamic content page (homepage, pricing, FAQ, etc). Sections use a free-text key, so you can add new ones without a code change.
        </p>
      </div>

      <Card>
        <h2 className="mb-3 text-sm font-semibold text-slate-700 dark:text-slate-200">New page</h2>
        <form onSubmit={handleCreate} className="flex flex-wrap items-end gap-3">
          <Input label="Slug" required value={newSlug} onChange={(e) => setNewSlug(e.target.value)} placeholder="homepage" />
          <Input label="Title" required value={newTitle} onChange={(e) => setNewTitle(e.target.value)} placeholder="Homepage" />
          <Button type="submit">Create page</Button>
        </form>
        {createError && <p className="mt-2 text-sm text-red-600 dark:text-red-400">{createError}</p>}
      </Card>

      {error && <ErrorState message={error} onRetry={load} />}
      {!error && !pages && <Spinner />}
      {pages && pages.length === 0 && <EmptyState title="No CMS pages yet" description="Create one above." />}

      {pages && pages.length > 0 && (
        <div className="flex flex-col gap-4">
          {pages.map((page) => (
            <Card key={page.id}>
              <div className="flex items-center justify-between">
                <div>
                  <h2 className="font-semibold text-slate-900 dark:text-white">{page.title}</h2>
                  <p className="text-xs text-slate-400">/{page.slug}</p>
                </div>
                <Button variant="danger" onClick={() => handleDeletePage(page.id)}>
                  Delete page
                </Button>
              </div>
              <SectionEditor page={page} onSaved={load} />
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
