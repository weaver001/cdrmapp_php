# Deployment preparation

Local Compose is a development topology. `artisan serve`, local Mailpit, debug settings, and debug APK signing are not production deployment choices.

Before deployment build a PHP 8.4 FPM or equivalent supported application-server image from the locked backend, run behind a TLS reverse proxy, serve only backend/public, disable APP_DEBUG, set APP_ENV=production and HTTPS APP_URL, configure trusted proxies explicitly, enforce secure HttpOnly SameSite session cookies, restrict CORS to intended clients, and prohibit public access to private storage. A separately supervised queue worker must run php artisan queue:work --tries=3 --timeout=60. Schedule token pruning and production maintenance; alert on failed jobs and lag. Configure real mail, private evidence storage, Google keys/restrictions/quotas and Firebase before claiming these integrations work.

Run composer install --no-dev --prefer-dist with the lockfile, npm ci && npm run build in the frontend build stage, migrations with backups and a controlled rollout, and Laravel configuration/route/view caching. Do not run local account fixture generation or fake-incident seeds in production. Seed only reviewed agency/category configuration. Generate the application key once and retain it securely. Grant MySQL least-privilege database access and require authenticated encrypted remote transport.

Publish Android using a securely managed release signing key, a real HTTPS API URL, restricted Maps configuration, reviewed privacy notices and foreground location permissions. Never embed MySQL access in the APK. Test on physical devices for stale GPS, approximate location, denied permissions, interruptions, notification permission denial, accessibility and screen-reader confirmation.

The current task validates local development only. A production FPM image, deployment automation, backups/restores and signed release deployment are not yet built or tested. This document records prerequisites rather than asserting production readiness.
