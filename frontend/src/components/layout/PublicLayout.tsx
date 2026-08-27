import { useState } from 'react';
import { Link, NavLink, Outlet } from 'react-router-dom';
import { useAuth } from '../../contexts/AuthContext';

const navItems = [
  { to: '/services', label: 'Services' },
  { to: '/how-it-works', label: 'How It Works' },
  { to: '/pricing', label: 'Pricing' },
  { to: '/faq', label: 'FAQ' },
  { to: '/contact', label: 'Contact' },
];

export function PublicLayout() {
  const { user } = useAuth();
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

  const navLinkClass = ({ isActive }: { isActive: boolean }) =>
    `text-sm font-medium ${isActive ? 'text-indigo-600 dark:text-indigo-400' : 'text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white'}`;

  return (
    <div className="flex min-h-screen flex-col bg-slate-50 dark:bg-slate-950">
      <header className="border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div className="mx-auto flex max-w-6xl items-center justify-between px-4 py-4">
          <Link to="/" className="text-lg font-bold text-slate-900 dark:text-white">
            Audivio
          </Link>
          <nav className="hidden gap-6 md:flex" aria-label="Main navigation">
            {navItems.map((item) => (
              <NavLink key={item.to} to={item.to} className={navLinkClass}>
                {item.label}
              </NavLink>
            ))}
          </nav>
          <div className="flex items-center gap-3">
            <div className="hidden items-center gap-3 md:flex">
              {user ? (
                <Link to="/dashboard" className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                  Dashboard
                </Link>
              ) : (
                <>
                  <Link to="/login" className="text-sm font-medium text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">
                    Log in
                  </Link>
                  <Link to="/signup" className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                    Sign up
                  </Link>
                </>
              )}
            </div>
            <button
              type="button"
              className="rounded-md p-2 text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800 md:hidden"
              aria-label={mobileMenuOpen ? 'Close menu' : 'Open menu'}
              aria-expanded={mobileMenuOpen}
              onClick={() => setMobileMenuOpen((open) => !open)}
            >
              {mobileMenuOpen ? '✕' : '☰'}
            </button>
          </div>
        </div>

        {mobileMenuOpen && (
          <nav className="border-t border-slate-200 px-4 py-3 dark:border-slate-800 md:hidden" aria-label="Main navigation">
            <div className="flex flex-col gap-3">
              {navItems.map((item) => (
                <NavLink key={item.to} to={item.to} className={navLinkClass} onClick={() => setMobileMenuOpen(false)}>
                  {item.label}
                </NavLink>
              ))}
              <div className="mt-2 flex flex-col gap-3 border-t border-slate-100 pt-3 dark:border-slate-800">
                {user ? (
                  <Link to="/dashboard" className="text-sm font-medium text-indigo-600 dark:text-indigo-400" onClick={() => setMobileMenuOpen(false)}>
                    Dashboard
                  </Link>
                ) : (
                  <>
                    <Link to="/login" className="text-sm font-medium text-slate-600 dark:text-slate-300" onClick={() => setMobileMenuOpen(false)}>
                      Log in
                    </Link>
                    <Link to="/signup" className="text-sm font-medium text-indigo-600 dark:text-indigo-400" onClick={() => setMobileMenuOpen(false)}>
                      Sign up
                    </Link>
                  </>
                )}
              </div>
            </div>
          </nav>
        )}
      </header>

      <main className="flex-1">
        <Outlet />
      </main>

      <footer className="border-t border-slate-200 bg-white py-10 dark:border-slate-800 dark:bg-slate-900">
        <div className="mx-auto flex max-w-6xl flex-col items-center gap-4 px-4 text-sm text-slate-500 dark:text-slate-400 sm:flex-row sm:justify-between">
          <p>&copy; {new Date().getFullYear()} Audivio. All rights reserved.</p>
          <nav className="flex flex-wrap justify-center gap-4" aria-label="Footer navigation">
            <Link to="/services" className="hover:text-slate-800 dark:hover:text-slate-200">Services</Link>
            <Link to="/how-it-works" className="hover:text-slate-800 dark:hover:text-slate-200">How It Works</Link>
            <Link to="/pricing" className="hover:text-slate-800 dark:hover:text-slate-200">Pricing</Link>
            <Link to="/faq" className="hover:text-slate-800 dark:hover:text-slate-200">FAQ</Link>
            <Link to="/contact" className="hover:text-slate-800 dark:hover:text-slate-200">Contact</Link>
          </nav>
        </div>
      </footer>
    </div>
  );
}
