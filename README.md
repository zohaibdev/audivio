# Audivio

AI-driven website audit SaaS. Crawls websites, runs deterministic + AI-assisted
audits (performance, accessibility, SEO, ecommerce, landing page, conversion,
AI Search Readiness), scores them, and converts users into SaaS customers or
consulting leads.

See [CLAUDE.md](CLAUDE.md) for the full project brief and architecture decisions.

## Structure

- `backend/` — Laravel 12 API (MySQL, Redis queues, Sanctum token auth)
- `frontend/` — React 19 + TypeScript SPA (Vite)
- `crawler/` — Node/Playwright page crawler, invoked as a subprocess by the
  backend's queue jobs (`App\Services\Crawler\PlaywrightCrawlerClient`)

## Local development

### Backend

```bash
cd backend
composer install
cp .env.example .env   # already done on first setup
php artisan key:generate
php artisan migrate
php artisan serve
```

Queue worker (required — crawling and AI analysis run on queues, never synchronously):

```bash
php artisan queue:work redis
```

> **Horizon note:** Laravel Horizon requires the `pcntl`/`posix` PHP extensions,
> which do not exist on Windows. Use `php artisan queue:work` for local Windows
> development; add Horizon when deploying to a Linux environment.

### Frontend

```bash
cd frontend
npm install
cp .env.example .env   # already done on first setup
npm run dev
```

### Crawler

```bash
cd crawler
npm install   # also downloads Chromium via the postinstall hook
```

Nothing to run directly — the backend invokes `crawler/crawl.mjs` as a
subprocess. Test the whole pipeline with:

```bash
php artisan audit:crawl https://example.com --pages=5 --wait
```

## Requirements

- PHP 8.2+, Composer
- Node 20+, npm
- MySQL
- Redis
