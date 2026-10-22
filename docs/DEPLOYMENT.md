# Deployment preparation

Local Compose is a development topology. `artisan serve`, local Mailpit, debug settings, and debug APK signing are not production deployment choices.

Before deployment build a PHP 8.4 FPM or equivalent supported application-server image from the locked backend, run behind a TLS reverse proxy, serve only backend/public, disable APP_DEBUG, set APP_ENV=production and HTTPS APP_URL, configure trusted proxies explicitly, enforce secure HttpOnly SameSite session cookies, restrict CORS to intended clients, and prohibit public access to private storage. A separately supervised queue worker must run php artisan queue:work --tries=3 --timeout=60. Schedule token pruning and production maintenance; alert on failed jobs and lag. Configure real mail, private evidence storage, Google keys/restrictions/quotas and Firebase before claiming these integrations work.

Run composer install --no-dev --prefer-dist with the lockfile, npm ci && npm run build in the frontend build stage, migrations with backups and a controlled rollout, and Laravel configuration/route/view caching. Do not run local account fixture generation or fake-incident seeds in production. Seed only reviewed agency/category configuration. Generate the application key once and retain it securely. Grant MySQL least-privilege database access and require authenticated encrypted remote transport.

Publish Android using a securely managed release signing key, a real HTTPS API URL, restricted Maps configuration, reviewed privacy notices and foreground location permissions. Never embed MySQL access in the APK. Test on physical devices for stale GPS, approximate location, denied permissions, interruptions, notification permission denial, accessibility and screen-reader confirmation.

The current task validates local development only. A production FPM image, deployment automation, backups/restores and signed release deployment are not yet built or tested. This document records prerequisites rather than asserting production readiness.


## Laravel Cloud: persistent CMS image uploads

Announcement, infographic, service, and team images can use a private Laravel Cloud Object Storage bucket. The application records the disk alongside each image and returns a 30-minute signed `image_url` in public content responses. Reload the page or fetch the API again after a link expires; do not cache content responses or rendered HTML beyond this lifetime. Only published content is returned by public endpoints. Treat signed links as bearer links: someone who receives a link can use it until it expires, even if the item is subsequently unpublished.

1. Deploy the code containing the S3 adapter and image-disk migration. Keep `php artisan migrate --force` in the deployment commands.
2. Open the application's **production environment** in Laravel Cloud. On its infrastructure canvas, choose **Add bucket**, then create **Laravel Object Storage**. Review the displayed storage costs before creating it.
3. Use a name such as `cdrmapp-media`, disk name **s3**, and **Private** file visibility. Choose Automatic jurisdiction and an Asia-Pacific location hint for the Singapore app region if those choices are available. Attach it to the production environment with read/write access and make it the default disk so Cloud injects its connection settings.
4. In the environment's custom variables, set `CMS_MEDIA_DISK=s3`. Laravel Cloud supplies the attached bucket's connection settings; do not paste credentials into source control or chat. Remove conflicting manually entered AWS connection variables if they would override Cloud's bucket settings. The app supports `AWS_ENDPOINT` / `AWS_ENDPOINT_URL` and `AWS_DEFAULT_REGION` / `AWS_REGION`. You do not need `AWS_URL` for this private bucket. Do not set public object visibility or ACLs: Cloudflare R2 uses bucket-level visibility.
5. Redeploy after attaching the bucket and saving variables. Make sure the migration succeeded. If managing config caching manually, rebuild it after the variables are applied (`php artisan config:cache`).
6. Edit the announcement in Admin, select the image again, and save. Open `/pages/announcements` in a private browser window. The image request should return HTTP 200 from the bucket with a signed URL, not `/storage/...`. Redeploy once more and confirm that a fresh page still displays it.

Old uploads retain their original `public` disk location; setting `CMS_MEDIA_DISK` does not move them. If they still exist, back them up before redeploying and re-upload through Admin. Missing files on ephemeral servers cannot be recovered by this change. `storage:link` is only needed for local/public disk images and does not provide persistent cloud storage.

This configuration covers CMS images only. Profile photos and incident evidence still explicitly use the private **local** disk and need a separate persistent private-storage implementation before relying on their durability in production. Never place evidence in a public bucket.

The tests validate disk selection, replacement, failed writes, publication filtering, page/API URLs, and the real S3 adapter's URL signing locally. A live Laravel Cloud bucket is not provisioned or verified by these tests.

Reference: [Laravel Cloud Object Storage](https://cloud.laravel.com/docs/resources/object-storage).
