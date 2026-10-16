import { BrowserRouter, Route, Routes } from 'react-router-dom';
import { AuthProvider } from './contexts/AuthContext';
import { ThemeProvider } from './contexts/ThemeContext';
import { PublicLayout } from './components/layout/PublicLayout';
import { SidebarLayout } from './components/layout/SidebarLayout';
import { RequireAdmin, RequireAuth } from './components/layout/RouteGuards';

import { HomePage } from './pages/public/HomePage';
import { ServicesPage } from './pages/public/ServicesPage';
import { HowItWorksPage } from './pages/public/HowItWorksPage';
import { PricingPage } from './pages/public/PricingPage';
import { FaqPage } from './pages/public/FaqPage';
import { ContactPage } from './pages/public/ContactPage';
import { PrivacyPage } from './pages/public/PrivacyPage';
import { TermsPage } from './pages/public/TermsPage';
import { AccessibilityPage } from './pages/public/AccessibilityPage';

import { LoginPage } from './pages/auth/LoginPage';
import { SignupPage } from './pages/auth/SignupPage';
import { ForgotPasswordPage } from './pages/auth/ForgotPasswordPage';

import { SubmitAuditPage } from './pages/audit/SubmitAuditPage';
import { AuditStatusPage } from './pages/audit/AuditStatusPage';
import { AuditReportPage } from './pages/audit/AuditReportPage';
import { SharedReportPage } from './pages/public/SharedReportPage';

import { DashboardOverviewPage } from './pages/dashboard/DashboardOverviewPage';
import { DashboardWebsitesPage } from './pages/dashboard/DashboardWebsitesPage';
import { DashboardAuditsPage } from './pages/dashboard/DashboardAuditsPage';
import { DashboardSchedulesPage } from './pages/dashboard/DashboardSchedulesPage';
import { DashboardUsagePage } from './pages/dashboard/DashboardUsagePage';
import { DashboardProfilePage } from './pages/dashboard/DashboardProfilePage';
import { DashboardSubscriptionPage } from './pages/dashboard/DashboardSubscriptionPage';

import { AdminDashboardHomePage } from './pages/admin/AdminDashboardHomePage';
import { AdminUsersPage } from './pages/admin/AdminUsersPage';
import { AdminWebsitesPage } from './pages/admin/AdminWebsitesPage';
import { AdminAuditsPage } from './pages/admin/AdminAuditsPage';
import { AdminLeadsPage } from './pages/admin/AdminLeadsPage';
import { AdminProvidersPage } from './pages/admin/AdminProvidersPage';
import { AdminApiUsagePage } from './pages/admin/AdminApiUsagePage';
import { AdminAiUsagePage } from './pages/admin/AdminAiUsagePage';
import { AdminPlansPage } from './pages/admin/AdminPlansPage';
import { AdminCmsPage } from './pages/admin/AdminCmsPage';
import { AdminSettingsPage } from './pages/admin/AdminSettingsPage';
import { AdminSystemHealthPage } from './pages/admin/AdminSystemHealthPage';
import { AdminFailedJobsPage } from './pages/admin/AdminFailedJobsPage';

const dashboardNavItems = [
  { to: '/dashboard', label: 'Overview', end: true },
  { to: '/dashboard/websites', label: 'My Websites' },
  { to: '/dashboard/audits', label: 'Audits / History / Reports' },
  { to: '/dashboard/schedules', label: 'Scheduled Audits' },
  { to: '/dashboard/usage', label: 'Usage' },
  { to: '/dashboard/profile', label: 'Profile' },
  { to: '/dashboard/subscription', label: 'Subscription' },
];

const adminNavItems = [
  { to: '/admin', label: 'Dashboard', end: true },
  { to: '/admin/users', label: 'Users' },
  { to: '/admin/websites', label: 'Websites' },
  { to: '/admin/audits', label: 'Audits' },
  { to: '/admin/leads', label: 'Leads' },
  { to: '/admin/providers', label: 'AI & API Providers' },
  { to: '/admin/api-usage', label: 'API Usage / Logs' },
  { to: '/admin/ai-usage', label: 'AI Usage' },
  { to: '/admin/plans', label: 'Pricing Plans' },
  { to: '/admin/cms', label: 'CMS' },
  { to: '/admin/settings', label: 'Settings' },
  { to: '/admin/system-health', label: 'System Health' },
  { to: '/admin/failed-jobs', label: 'Failed Jobs' },
];

function App() {
  return (
    <BrowserRouter>
      <ThemeProvider>
        <AuthProvider>
        <Routes>
          <Route element={<PublicLayout />}>
            <Route path="/" element={<HomePage />} />
            <Route path="/services" element={<ServicesPage />} />
            <Route path="/how-it-works" element={<HowItWorksPage />} />
            <Route path="/pricing" element={<PricingPage />} />
            <Route path="/faq" element={<FaqPage />} />
            <Route path="/contact" element={<ContactPage />} />
            <Route path="/privacy" element={<PrivacyPage />} />
            <Route path="/terms" element={<TermsPage />} />
            <Route path="/accessibility" element={<AccessibilityPage />} />
            <Route path="/login" element={<LoginPage />} />
            <Route path="/signup" element={<SignupPage />} />
            <Route path="/forgot-password" element={<ForgotPasswordPage />} />
            <Route path="/audit" element={<SubmitAuditPage />} />
            <Route path="/audits/:auditId" element={<AuditStatusPage />} />
            {/* Per-service reports (§35). The bare /report path shows the
                overview; /report/:service opens one service's own report. */}
            <Route path="/audits/:auditId/report" element={<AuditReportPage />} />
            <Route path="/audits/:auditId/report/:service" element={<AuditReportPage />} />
          </Route>

          <Route element={<RequireAuth />}>
            <Route element={<SidebarLayout title="Dashboard" navItems={dashboardNavItems} />}>
              <Route path="/dashboard" element={<DashboardOverviewPage />} />
              <Route path="/dashboard/websites" element={<DashboardWebsitesPage />} />
              <Route path="/dashboard/audits" element={<DashboardAuditsPage />} />
              <Route path="/dashboard/schedules" element={<DashboardSchedulesPage />} />
              <Route path="/dashboard/usage" element={<DashboardUsagePage />} />
              <Route path="/dashboard/profile" element={<DashboardProfilePage />} />
              <Route path="/dashboard/subscription" element={<DashboardSubscriptionPage />} />
            </Route>
          </Route>

          <Route element={<RequireAdmin />}>
            <Route element={<SidebarLayout title="Admin" navItems={adminNavItems} />}>
              <Route path="/admin" element={<AdminDashboardHomePage />} />
              <Route path="/admin/users" element={<AdminUsersPage />} />
              <Route path="/admin/websites" element={<AdminWebsitesPage />} />
              <Route path="/admin/audits" element={<AdminAuditsPage />} />
              <Route path="/admin/leads" element={<AdminLeadsPage />} />
              <Route path="/admin/providers" element={<AdminProvidersPage />} />
              <Route path="/admin/api-usage" element={<AdminApiUsagePage />} />
              <Route path="/admin/ai-usage" element={<AdminAiUsagePage />} />
              <Route path="/admin/plans" element={<AdminPlansPage />} />
              <Route path="/admin/cms" element={<AdminCmsPage />} />
              <Route path="/admin/settings" element={<AdminSettingsPage />} />
              <Route path="/admin/system-health" element={<AdminSystemHealthPage />} />
              <Route path="/admin/failed-jobs" element={<AdminFailedJobsPage />} />
            </Route>
          </Route>

          {/* Shared reports (§50). Outside every layout: a recipient
              has no account, so the app's own navigation would offer
              them nothing but links they cannot use. */}
          <Route path="/shared-report/:token" element={<SharedReportPage />} />

          <Route path="*" element={<NotFoundPage />} />
        </Routes>
        </AuthProvider>
      </ThemeProvider>
    </BrowserRouter>
  );
}

function NotFoundPage() {
  return (
    <div className="flex min-h-screen flex-col items-center justify-center gap-2 text-center">
      <h1 className="text-3xl font-bold text-slate-900 dark:text-white">Page not found</h1>
      <a href="/" className="text-indigo-600 hover:underline dark:text-indigo-400">
        Back to home
      </a>
    </div>
  );
}

export default App;
