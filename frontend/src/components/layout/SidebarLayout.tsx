import { useState } from 'react';
import { Link, NavLink, Outlet } from 'react-router-dom';
import { useAuth } from '../../contexts/AuthContext';

export interface SidebarNavItem {
  to: string;
  label: string;
  end?: boolean;
}

export function SidebarLayout({ title, navItems }: { title: string; navItems: SidebarNavItem[] }) {
  const { user, logout } = useAuth();
  const [mobileOpen, setMobileOpen] = useState(false);

  const navLinkClass = ({ isActive }: { isActive: boolean }) =>
    `block rounded-md px-3 py-2 text-sm font-medium ${
      isActive
        ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300'
        : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'
    }`;

  return (
    <div className="flex min-h-screen bg-slate-50 dark:bg-slate-950">
      <aside
        className={`fixed inset-y-0 left-0 z-20 w-64 transform border-r border-slate-200 bg-white p-4 transition-transform dark:border-slate-800 dark:bg-slate-900 md:static md:translate-x-0 ${
          mobileOpen ? 'translate-x-0' : '-translate-x-full'
        }`}
      >
        <Link to="/" className="mb-6 block text-lg font-bold text-slate-900 dark:text-white">
          Audivio
        </Link>
        <p className="mb-2 px-3 text-xs font-semibold uppercase tracking-wide text-slate-400">{title}</p>
        <nav className="flex flex-col gap-1" aria-label={`${title} navigation`}>
          {navItems.map((item) => (
            <NavLink key={item.to} to={item.to} end={item.end} className={navLinkClass} onClick={() => setMobileOpen(false)}>
              {item.label}
            </NavLink>
          ))}
        </nav>
      </aside>

      {mobileOpen && (
        <button
          type="button"
          aria-label="Close menu"
          className="fixed inset-0 z-10 bg-black/30 md:hidden"
          onClick={() => setMobileOpen(false)}
        />
      )}

      <div className="flex flex-1 flex-col md:pl-0">
        <header className="flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3 dark:border-slate-800 dark:bg-slate-900">
          <button
            type="button"
            className="rounded-md p-2 text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800 md:hidden"
            onClick={() => setMobileOpen(true)}
            aria-label="Open menu"
          >
            ☰
          </button>
          <div className="ml-auto flex items-center gap-4">
            <span className="text-sm text-slate-600 dark:text-slate-300">{user?.name}</span>
            <button
              type="button"
              onClick={() => void logout()}
              className="text-sm font-medium text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-white"
            >
              Log out
            </button>
          </div>
        </header>
        <main className="flex-1 p-4 md:p-8">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
