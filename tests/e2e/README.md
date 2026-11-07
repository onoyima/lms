# Browser tests

`smoke.mjs` drives a real Chromium through the platform as an administrator, a lecturer, a student and an
exams officer: every main page must open without an HTTP or JavaScript error and fit a phone screen, and the
main journeys are walked (schedule a live class, open a score board and download Excel, enter an exam,
answer, zoom, open the submit confirmation and go back).

Run it only against a local or staging copy that has the demo data — never the live database:

```
php artisan migrate:fresh --seed && php artisan db:seed --class=DemoSeeder
npx playwright install chromium        # once (or use a global Playwright: NODE_PATH="$(npm root -g)")
node tests/e2e/smoke.mjs http://127.0.0.1:8000
E2E_SHOTS=storage/e2e node tests/e2e/smoke.mjs http://127.0.0.1:8000   # also saves a screenshot of every page
```

`E2E_PASSWORD` sets the demo accounts' password if you seeded with `SEED_ADMIN_PASSWORD`.
