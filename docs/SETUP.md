# Configuration and integrations

Keep all local settings in ignored backend/.env, infra/.env, android/local.properties, user Gradle properties, or secure environment injection. Root .env.example documents variable names; application settings live in backend/.env.example. Never commit real credentials or service-account files.

## Maps

Web: set GOOGLE_MAPS_WEB_KEY in backend/.env. Enable Google Maps JavaScript API and billing; restrict by exact production HTTP referrers and required APIs. Android: provide GOOGLE_MAPS_ANDROID_KEY as a Gradle property or environment variable, enable Maps SDK for Android, restrict to org.cdrmapp.citizen plus the appropriate debug/release signing SHA. Keys are client-visible identifiers and require restrictions. Use separate keys, quotas and billing alerts. Do not put a server credential in either client. Web uses deck.gl HeatmapLayer on GoogleMapsOverlay; it never references the removed Google visualization heatmap.

No key was supplied in this task. Map rendering and billed Google API behavior are unverified. Non-map screens show a configuration message rather than fake tiles.

## Mail and authentication

Local SMTP is Mailpit on port 1025 in Compose; only its local UI is exposed on port 8025. Production requires verified mail transport/domain settings and a correct HTTPS APP_URL so signed verification and reset links return to the real deployment. Registration requires verification before incident access. Authentication itself remains available to unverified users so they can request another link. Do not implement the source document's inconsistent first-login exception.

Mobile tokens expire after one day. Web uses server sessions; mobile uses Android Keystore AES/GCM. Secure cookie, HTTPS, SameSite, CORS and proxy configuration must be reviewed for deployment. Backend API_URL and Android base URL must point to the same real database-owning Laravel service.

## Firebase

Backend supports queued FCM HTTP v1 status notifications. Set FCM_ENABLED=true and FCM_PROJECT_ID. Supply either FCM_SERVICE_ACCOUNT_JSON as a secret containing the complete Firebase Admin JSON, or FCM_SERVICE_ACCOUNT_FILE pointing to a securely mounted, readable service-account JSON file outside the checkout. The JSON secret takes precedence. For Docker, set FCM_SERVICE_ACCOUNT_FILE_HOST to the existing secure host file and add `-f infra/compose.fcm.yaml` after `-f infra/compose.yaml` to Compose commands; the overlay mounts it read-only at /run/secrets/firebase-service-account.json. This overlay is a template and was not tested with a live service account. Grant only required Firebase messaging permissions. OAuth uses oauth2.googleapis.com and sends messages to fcm.googleapis.com. Never paste credentials into chat, source control, scripts or proxy placeholder variables. See DEPLOYMENT.md for Laravel Cloud secret and worker configuration.

Android Firebase initialization uses FIREBASE_APPLICATION_ID, FIREBASE_PROJECT_ID, FIREBASE_API_KEY, FIREBASE_SENDER_ID Gradle properties/environment. Values come from the registered Firebase Android application configuration. Device registration is tied to the authenticated account. Android requests notification permission explicitly; foreground/background delivery depends on device and platform conditions. Staff dashboards poll durable database records every 15 seconds and are independent of FCM.

FCM recipient isolation and provider handling are tested using a fake provider. No real Firebase project, device delivery, or production credentials were validated.

## Platform and network

Docker images are digest-pinned in infra. Composer archives are installed using lockfiles and normal TLS/checksum verification. The cloud proxy's CA is mounted into Composer when SSL_CERT_FILE exists. The optional codeload cache helper downloads the exact locked GitHub reference from GitHub's official codeload endpoint, validates ZIP integrity and any supplied archive checksum, and leaves lock metadata intact.

Android tooling requires dl.google.com, repo.maven.apache.org, plugins.gradle.org, downloads.gradle.org/services.gradle.org and their artifact redirects. Runtime maps use maps.googleapis.com, maps.gstatic.com and Google tile domains; Firebase additionally needs the HTTPS OAuth and FCM destinations. No unrestricted policy is necessary. Current network additions are saved separately in the environment draft.

Cloud tasks are already isolated. Use the existing /workspace/cdrmapp_php checkout; do not create Git worktrees unless explicitly requested. Retained dependencies do not imply retained running processes: start Compose services in every new task and check the database, homepage and queue.
