import { AuditUrlForm } from '../../components/audit/AuditUrlForm';

export function SubmitAuditPage() {
  return (
    <div className="mx-auto flex min-h-[70vh] max-w-xl flex-col justify-center px-4 py-16 text-center">
      <h1 className="text-3xl font-bold text-slate-900 dark:text-white">Audit your website</h1>
      <p className="mt-2 text-slate-600 dark:text-slate-300">
        Enter your website address below. We'll crawl it and run all six audits automatically.
      </p>
      <div className="mt-8">
        <AuditUrlForm />
      </div>
    </div>
  );
}
