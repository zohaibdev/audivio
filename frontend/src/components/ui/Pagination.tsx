export function Pagination({
  currentPage,
  lastPage,
  total,
  onChange,
}: {
  currentPage: number;
  lastPage: number;
  total: number;
  onChange: (page: number) => void;
}) {
  return (
    <div className="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-sm dark:border-line">
      <span className="text-muted">
        Page {currentPage} of {lastPage} — {total} total
      </span>
      <div className="flex gap-2">
        <button
          type="button"
          disabled={currentPage <= 1}
          onClick={() => onChange(currentPage - 1)}
          className="rounded-md border border-slate-300 px-3 py-1 disabled:opacity-40 dark:border-slate-700"
        >
          Prev
        </button>
        <button
          type="button"
          disabled={currentPage >= lastPage}
          onClick={() => onChange(currentPage + 1)}
          className="rounded-md border border-slate-300 px-3 py-1 disabled:opacity-40 dark:border-slate-700"
        >
          Next
        </button>
      </div>
    </div>
  );
}
