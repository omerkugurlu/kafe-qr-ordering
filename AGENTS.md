# Repository Guidelines

## Project Structure & Modules

The primary application is a React and TypeScript QR ordering app. `src/` contains pages, authentication, domain logic, and server/API modules; `netlify/functions/` contains the Netlify Functions entry point. Public assets and the Vite output go to `public/` and `dist/`. The repository also retains a Laravel implementation: PHP code is in `app/`, routes in `routes/`, Blade and frontend assets in `resources/`, and database migrations/factories/seeders in `database/`.

Tests are grouped by stack: Vitest files sit beside TypeScript modules as `*.test.ts`; Laravel tests are in `tests/Unit` and `tests/Feature`; Playwright flows are in `tests/e2e`.

## Build, Test & Local Development

Run `npm ci` to install the locked Node dependencies and `npm run dev` to start Vite locally. `npm run typecheck` checks strict TypeScript types, `npm test` runs Vitest, and `npm run build` type-checks and builds the Netlify app. For browser flows, install Chromium with `npx playwright install chromium`, then run `npm run test:e2e`.

For the Laravel app, install PHP dependencies with `composer install`, run `php artisan test --compact` for PHPUnit tests, `vendor/bin/pint --dirty` to format changed PHP, and `npm run build:laravel-assets` to build Blade assets.

## Style & Naming

Follow `.editorconfig`: UTF-8, LF endings, and four spaces. Keep TypeScript strict; use PascalCase for React components and tests, camelCase for functions and variables, and descriptive names. Follow neighboring Laravel conventions and PSR-12; use Pint for PHP formatting. Prefer existing components and helpers over duplicate implementations.

## Tests

Add focused tests alongside the module they cover, using `*.test.ts` for Vitest and `*Test.php` for PHPUnit. Cover changed behavior and important edge cases. Run the relevant stack’s checks before submitting; UI changes should include a brief manual or Playwright verification note.

## Commits & Pull Requests

Recent history uses Conventional Commit prefixes such as `feat:` and `fix:`. Keep commits focused and explain the user-visible change. PRs should describe behavior, list checks run, note configuration or migration needs, and include screenshots for visible UI changes.

## Security & Configuration

Keep secrets in ignored local environment files such as `.env.local`; never commit tokens, passwords, production database files, or generated `dist/`, `public/build/`, and test output. Configure production secrets through the hosting environment.