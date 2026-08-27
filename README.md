# Audivio

AI-driven website audit SaaS. Crawls websites, runs deterministic + AI-assisted
audits (performance, accessibility, SEO, ecommerce, landing page, conversion,
AI Search Readiness), scores them, and converts users into SaaS customers or
consulting leads.

See [CLAUDE.md](CLAUDE.md) for the full project brief and architecture decisions.

## Structure

- `backend/` — Laravel 12 API (MySQL, Redis queues, Sanctum token auth)
- `frontend/` — React 19 + TypeScript SPA (Vite)

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

## Requirements

- PHP 8.2+, Composer
- Node 20+, npm
- MySQL
- Redis
