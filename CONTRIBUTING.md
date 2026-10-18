# Contributing

Thanks for taking the time to help. Bug reports, fixes and small improvements are all welcome.

## Getting set up

Follow **Local setup** in the [README](README.md). You'll need PHP 8.3+, Composer, Docker (for PostgreSQL and Redis), and Node 20+.

## Before opening a pull request

1. Create a branch from `main`.
2. Keep changes focused — one fix or feature per PR.
3. Run the checks:
   ```bash
   cd api && php artisan test && ./vendor/bin/pint --test
   cd web && npm run lint && npm run build
   ```
4. Add or update tests for behaviour you change (API feature tests live in `api/tests/Feature`).
5. Describe what changed and why in the PR, and include a screenshot for UI changes.

## Code style

- PHP follows Laravel Pint defaults.
- The frontend is plain JavaScript/JSX (no TypeScript) with Tailwind.
- Comment only where the code isn't self-explanatory.

## Reporting bugs

Open an issue using the bug report template. For security problems, see [SECURITY.md](SECURITY.md) instead.

## Licensing of contributions

BugWatcher Pings is licensed under the [PolyForm Noncommercial License 1.0.0](LICENSE) and is also offered under a paid commercial license. By contributing, you agree that your contribution may be distributed under both, and that you'll follow the [Code of Conduct](CODE_OF_CONDUCT.md).
