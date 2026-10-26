# Deployment preparation

Local Compose is a development topology. `artisan serve`, local Mailpit, debug settings, and debug APK signing are not production deployment choices.

Before deployment build a PHP 8.4 FPM or equivalent supported application-server image from the locked backend, run behind a TLS reverse proxy, serve only backend/public, disable APP_DEBUG, set APP_ENV=production and HTTPS APP_URL, configure trusted proxies explicitly, enforce secure HttpOnly SameSite session cookies, restrict CORS to intended clients, and prohibit public access to private storage. A separately supervised queue worker must run php artisan queue:work --tries=3 --timeout=60. Schedule token pruning and production maintenance; alert on failed jobs and lag. Configure real mail, private evidence storage, Google keys/restrictions/quotas and Firebase before claiming these integrations work.

Run composer install --no-dev --prefer-dist with the lockfile, npm ci && npm run build in the frontend build stage, migrations with backups and a controlled rollout, and Laravel configuration/route/view caching. Do not run local account fixture generation or fake-incident seeds in production. Seed only reviewed agency/category configuration. Generate the application key once and retain it securely. Grant MySQL least-privilege database access and require authenticated encrypted remote transport.

Publish Android using a securely managed release signing key, a real HTTPS API URL, restricted Maps configuration, reviewed privacy notices and foreground location permissions. Never embed MySQL access in the APK. Test on physical devices for stale GPS, approximate location, denied permissions, interruptions, notification permission denial, accessibility and screen-reader confirmation.

The current task validates local development only. A production FPM image, deployment automation, backups/restores and signed release deployment are not yet built or tested. This document records prerequisites rather than asserting production readiness.


## Laravel Cloud: persistent CMS image uploads

Announcement, service, and team images can use a private Laravel Cloud Object Storage bucket. The application records the disk alongside each image and returns a 30-minute signed `image_url` in public content responses. Reload the page or fetch the API again after a link expires; do not cache content responses or rendered HTML beyond this lifetime. Only published content is returned by public endpoints. Treat signed links as bearer links: someone who receives a link can use it until it expires, even if the item is subsequently unpublished.

1. Deploy the code containing the S3 adapter and image-disk migration. Keep `php artisan migrate --force` in the deployment commands.
2. Open the application's **production environment** in Laravel Cloud. On its infrastructure canvas, choose **Add bucket**, then create **Laravel Object Storage**. Review the displayed storage costs before creating it.
3. Use a name such as `cdrmapp-media`, disk name **private**, and **Private** file visibility. Choose Automatic jurisdiction and an Asia-Pacific location hint for the Singapore app region if those choices are available. Attach it to the production environment with read/write access and make it the default disk so Cloud injects its connection settings.
4. In the environment's custom variables, set `CMS_MEDIA_DISK=private` (or the exact disk name shown on your attached bucket). Laravel Cloud supplies the attached bucket's connection settings; do not paste credentials into source control or chat. Remove conflicting manually entered AWS connection variables if they would override Cloud's bucket settings. The app supports `AWS_ENDPOINT` / `AWS_ENDPOINT_URL` and `AWS_DEFAULT_REGION` / `AWS_REGION`. You do not need `AWS_URL` for this private bucket. Do not set public object visibility or ACLs: Cloudflare R2 uses bucket-level visibility.
5. Redeploy after attaching the bucket and saving variables. Make sure the migration succeeded. If managing config caching manually, rebuild it after the variables are applied (`php artisan config:cache`).
6. Edit the announcement in Admin, select the image again, and save. Open `/pages/announcements` in a private browser window. The image request should return HTTP 200 from the bucket with a signed URL, not `/storage/...`. Redeploy once more and confirm that a fresh page still displays it.

Old uploads retain their original `public` disk location; setting `CMS_MEDIA_DISK` does not move them. If they still exist, back them up before redeploying and re-upload through Admin. Missing files on ephemeral servers cannot be recovered by this change. `storage:link` is only needed for local/public disk images and does not provide persistent cloud storage.

If saving an announcement produces no visible result, check the Admin status above the form. It now reports required-field validation, saving, uploading, and upload failures separately. A successful image upload reports **Content and image saved successfully.** The record card shows the saved image or **No image attached.** A timeout does not prove that the server stopped processing: refresh and check the saved image before retrying. If a saved image fails to load, inspect its image request's HTTP status; if no image is attached, inspect the POST request ending in `/image`. Share only status codes and error messages, not credentials, cookies, or signed URLs. The isolated browser regression check is `node infra/cms-media-smoke.cjs`; it intercepts HTTP requests and does not connect to a real bucket.

This configuration covers CMS images only. Profile photos and incident evidence still explicitly use the private **local** disk and need a separate persistent private-storage implementation before relying on their durability in production. Never place evidence in a public bucket.

The tests validate disk selection, replacement, failed writes, publication filtering, page/API URLs, and the real S3 adapter's URL signing locally. A live Laravel Cloud bucket is not provisioned or verified by these tests.

Reference: [Laravel Cloud Object Storage](https://cloud.laravel.com/docs/resources/object-storage).


## Simplified reports and module removal deployment

Deploy the updated PHP code and frontend build together. No new database migration is required for this revision. Reports show Done/Need action, with reversible Mark as done/Mark as undone actions. Legacy resolved, closed, rejected and duplicate rows read as Done; other legacy states read as Need action. Historical rows/status history are not bulk-rewritten. CSV now includes status labels and location/coordinates.

Use **Incident dashboard** (`/dashboard`) for reports and **Map & heatmap** (`/incident-map`) for the separate map. Filters are shared and carried across when switching pages. Dispatch Rules and Infographics management/API/public routes are removed. New reports route directly to their category's active agency, ignoring old dispatch settings. Old rule and infographic data remains stored for history/rollback. The public homepage now displays announcements.

Existing audit entries remain available; new changes include actor/subject and before/after details. Forms clear only after complete successful saves; failed image uploads retain the saved item's ID for retry.

## San Jose del Monte test reports

After deploying, run this in Laravel Cloud production's **Commands** when you want the synthetic demonstration batch:

```bash
php artisan cdrmapp:seed-test-reports
```

It creates exactly five Fire Department (BFP), five Crime / Police Department (PNP), and five MDRRMO reports. Re-running keeps the same fifteen reports without changing their coordinates or workflow state. All are visibly labeled TEST and marked `is_test=true`, so they appear in authorized report lists and map pins but are excluded from both heatmap modes. No email or push notifications are sent. The synthetic reporter is an inactive citizen account at a reserved `.invalid` address; no usable login credentials are produced. Active agencies and linked incident categories must already exist; the command does not modify routing settings or real accounts/reports.

Locations use randomized offsets within fifteen separate cells in the central San Jose del Monte area. Cell corners were checked against the [OpenStreetMap San Jose del Monte administrative boundary](https://www.openstreetmap.org/relation/15662634) (© OpenStreetMap contributors); addresses and events are synthetic and do not identify actual emergencies. The separate map page’s legend and pins use red flame (BFP), blue police badge (PNP), purple rescue cross (MDRRMO), and a neutral question mark for triage. Pins show the current report page and respect its filters. Clear filters, return to page one, and enable **Show incident pins** to inspect the batch.

Checks: `node infra/map-pins-smoke.cjs` intercepts Google Maps and API requests to validate agency/icon mapping, test labels, positions and anchors without real Maps quota. Backend tests validate counts, repeatability, preservation of existing data, heatmap exclusion, and absence of notifications.
