import { useEffect, useState } from 'react';
import { Link, NavLink, Outlet } from 'react-router-dom';
import { ThemeToggle } from '../ui/ThemeToggle';
import { useAuth } from '../../contexts/AuthContext';

/**
 * Public site chrome (§58).
 *
 * The header is sticky and frosted, and condenses once the page scrolls —
 * enough to signal state without the layout jumping, which is why the
 * height change is small and the element keeps a fixed outer height.
 *
 * Primary navigation is deliberately short: Pricing plus the audit CTA.
 * Services, How It Works and FAQ moved to the footer. They are still
 * routed and still linked from the homepage — a header that lists
 * everything makes the one action that matters compete with five links
 * that do not.
 */

const footerGroups: { title: string; links: { to: string; label: string }[] }[] = [
  {
    title: 'Product',
    links: [
      { to: '/audit', label: 'Run an audit' },
      { to: '/pricing', label: 'Pricing' },
      { to: '/how-it-works', label: 'How it works' },
    ],
  },
  {
    title: 'Services',
    links: [
      { to: '/services', label: 'All services' },
      { to: '/services#performance', label: 'Performance' },
      { to: '/services#accessibility', label: 'Accessibility' },
      { to: '/services#seo', label: 'SEO' },
    ],
  },
  {
    title: 'Resources',
    links: [
      { to: '/faq', label: 'FAQ' },
      { to: '/how-it-works', label: 'What an audit checks' },
      { to: '/accessibility', label: 'Accessibility statement' },
    ],
  },
  {
    title: 'Company',
    links: [
      { to: '/contact', label: 'Contact' },
      { to: '/login', label: 'Log in' },
      { to: '/signup', label: 'Create account' },
    ],
  },
  {
    title: 'Legal',
    links: [
      { to: '/privacy', label: 'Privacy' },
      { to: '/terms', label: 'Terms' },
    ],
  },
];

function Wordmark() {
  return (
    <span className="inline-flex items-center gap-2">
      <span
        aria-hidden="true"
        className="grid h-7 w-7 place-items-center rounded-lg bg-brand text-[13px] font-bold text-on-brand shadow-soft"
      >
        A
      </span>
      <span className="text-lg font-semibold tracking-tight text-ink">Audivio</span>
    </span>
  );
}

export function PublicLayout() {
  const { user } = useAuth();
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 8);

    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });

    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  const navLinkClass = ({ isActive }: { isActive: boolean }) =>
    `text-sm font-medium transition ${isActive ? 'text-brand' : 'text-muted hover:text-ink'}`;

  return (
    <div className="flex min-h-screen flex-col bg-canvas">
      <header
        className={`sticky top-0 z-50 border-b transition-all duration-300 ${
          scrolled ? 'glass border-line shadow-soft' : 'border-transparent bg-canvas'
        }`}
      >
        <div
          className={`mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 transition-all duration-300 ${
            scrolled ? 'py-2.5' : 'py-4'
          }`}
        >
          <Link to="/" aria-label="Audivio home">
            <Wordmark />
          </Link>

          <nav className="hidden items-center gap-7 md:flex" aria-label="Main navigation">
            <NavLink to="/pricing" className={navLinkClass}>
              Pricing
            </NavLink>
            <NavLink to="/contact" className={navLinkClass}>
              Contact
            </NavLink>
          </nav>

          <div className="flex items-center gap-2 sm:gap-3">
            {/* In the site chrome rather than on one page: a visitor who
                sets a theme expects it everywhere (§47). */}
            <ThemeToggle />

            <div className="hidden items-center gap-3 md:flex">
              {user ? (
                <Link
                  to="/dashboard"
                  className="rounded-control bg-brand px-4 py-2 text-sm font-medium text-on-brand shadow-soft transition hover:bg-brand-strong hover:shadow-lift"
                >
                  Dashboard
                </Link>
              ) : (
                <>
                  <Link to="/login" className="text-sm font-medium text-muted transition hover:text-ink">
                    Log in
                  </Link>
                  <Link
                    to="/audit"
                    className="rounded-control bg-brand px-4 py-2 text-sm font-medium text-on-brand shadow-soft transition hover:bg-brand-strong hover:shadow-lift"
                  >
                    Run free audit
                  </Link>
                </>
              )}
            </div>

            <button
              type="button"
              className="rounded-control p-2 text-muted transition hover:bg-surface-2 hover:text-ink md:hidden"
              aria-label={mobileMenuOpen ? 'Close menu' : 'Open menu'}
              aria-expanded={mobileMenuOpen}
              onClick={() => setMobileMenuOpen((open) => !open)}
            >
              <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                {mobileMenuOpen ? (
                  <path strokeLinecap="round" d="M6 6l12 12M18 6L6 18" />
                ) : (
                  <path strokeLinecap="round" d="M4 7h16M4 12h16M4 17h16" />
                )}
              </svg>
            </button>
          </div>
        </div>

        {mobileMenuOpen && (
          <nav className="glass border-t border-line px-4 py-4 md:hidden" aria-label="Main navigation">
            <div className="flex flex-col gap-3">
              <NavLink to="/pricing" className={navLinkClass} onClick={() => setMobileMenuOpen(false)}>
                Pricing
              </NavLink>
              <NavLink to="/contact" className={navLinkClass} onClick={() => setMobileMenuOpen(false)}>
                Contact
              </NavLink>

              <div className="mt-2 flex flex-col gap-3 border-t border-line pt-3">
                {user ? (
                  <Link to="/dashboard" className="text-sm font-medium text-brand" onClick={() => setMobileMenuOpen(false)}>
                    Dashboard
                  </Link>
                ) : (
                  <>
                    <Link to="/login" className="text-sm font-medium text-muted" onClick={() => setMobileMenuOpen(false)}>
                      Log in
                    </Link>
                    <Link
                      to="/audit"
                      className="rounded-control bg-brand px-4 py-2 text-center text-sm font-medium text-on-brand"
                      onClick={() => setMobileMenuOpen(false)}
                    >
                      Run free audit
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

      <footer className="border-t border-line bg-surface">
        <div className="mx-auto max-w-6xl px-4 py-14">
          <div className="grid gap-10 lg:grid-cols-[1.4fr_2.6fr]">
            <div>
              <Wordmark />
              <p className="mt-4 max-w-sm text-sm leading-relaxed text-muted">
                Automated website audits across performance, accessibility, SEO, conversion and AI search readiness —
                with findings you can act on, and nothing invented to fill a gap.
              </p>

              <Link
                to="/audit"
                className="mt-6 inline-flex rounded-control bg-brand px-4 py-2 text-sm font-medium text-on-brand shadow-soft transition hover:bg-brand-strong hover:shadow-lift"
              >
                Run a free audit
              </Link>
            </div>

            <div className="grid grid-cols-2 gap-8 sm:grid-cols-3 lg:grid-cols-5">
              {footerGroups.map((group) => (
                <div key={group.title}>
                  <h2 className="text-xs font-semibold uppercase tracking-wider text-faint">{group.title}</h2>
                  <ul className="mt-3 space-y-2">
                    {group.links.map((link) => (
                      <li key={`${group.title}-${link.to}-${link.label}`}>
                        <Link to={link.to} className="text-sm text-muted transition hover:text-ink">
                          {link.label}
                        </Link>
                      </li>
                    ))}
                  </ul>
                </div>
              ))}
            </div>
          </div>

          <div className="mt-12 flex flex-col items-center justify-between gap-3 border-t border-line pt-6 text-xs text-faint sm:flex-row">
            <p>&copy; {new Date().getFullYear()} Audivio. All rights reserved.</p>
            <p>Automated assessments — not a legal compliance certification.</p>
          </div>
        </div>
      </footer>
    </div>
  );
}
