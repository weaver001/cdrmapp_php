# CDRMAPP

Citizen Disaster Response, Crime and Emergency Incident Reporting and Heat Mapping System.
Laravel web + versioned REST API, one shared MySQL database, and native Kotlin/Compose Android client. The supplied master specification is preserved in `docs/MASTER_SPECIFICATION.md`; source-document text is in `docs/SOURCE_FUNCTIONS.txt`.

This is a local development implementation, not certified emergency dispatch software. See [implementation status](docs/IMPLEMENTATION_STATUS.md) for remaining work and verified outcomes.

## Local development

Requirements: Docker Engine with Compose, Node 22+, npm, Python 3, curl, and ripgrep. Windows: run these commands in WSL2 with Docker Desktop integration. Linux: run from a normal checkout.

```bash
bash infra/setup-local.sh
```

This preserves existing local configuration, generates local database passwords when absent, builds the PHP runtime, installs locked dependencies, migrates and seeds agency/category configuration, builds assets, and starts web, queue, MySQL, and Mailpit. It never resets the database. Composer requires repo.packagist.org, api.github.com, and codeload.github.com. In the managed cloud, if GitHub API downloads remain blocked, populate the cache first:

```bash
python3 infra/cache-github-dist.py /workspace/.cache/cdrmapp-composer
bash infra/setup-local.sh
```

Web listens on loopback port 8000; local captured mail on port 8025. No preview links are provided by cloud onboarding. Do not expose the development server or Mailpit publicly.

Create your own local administrator (the command prints a random password once; do not commit it):

```bash
docker compose -f infra/compose.yaml run --rm web php artisan cdrmapp:create-admin admin@example.test
```

No fixed passwords are committed. Browser validation uses clearly synthetic accounts whose credentials are stored only under ignored `backend/storage/app/private/` in this instance.

## Tests

```bash
# Isolated SQLite test database; never the development database.
docker compose -f infra/compose.yaml run --rm web vendor/bin/phpunit
# Create the dedicated MySQL test database once.
docker compose -f infra/compose.yaml exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root -e "CREATE DATABASE IF NOT EXISTS cdrmapp_testing; GRANT ALL PRIVILEGES ON cdrmapp_testing.* TO cdrmapp;"'
# Uses the forced cdrmapp_testing configuration.
docker compose -f infra/compose.yaml run --rm web vendor/bin/phpunit -c phpunit.mysql.xml
npm --prefix backend run build
```

`RefreshDatabase` tests modify their selected test database. Do not remove the forced database settings in either PHPUnit configuration. The base TestCase also overrides container-provided database settings and refuses a non-test database before migrations. Use PHPUnit directly for the alternate configuration; `artisan test --configuration` can pass the option twice in this framework version.

## Android

JDK 21 (including javac), Android SDK platform 36, build tools 35.0.0, and Gradle wrapper 8.13. Open `/android` in Android Studio. Configure SDK path in ignored `local.properties`. Linux cloud tool preparation is available with `bash infra/setup-android.sh`.

```bash
cd android
./gradlew :app:assembleDebug :app:testDebugUnitTest :app:lintDebug
# Supply your own HTTPS server and restricted Maps key via Gradle properties or environment.
# CDRMAPP_API_URL must end with /api/v1/.
```

Debug builds permit HTTP for local emulator development only. The usual emulator host address is 10.0.2.2; set `CDRMAPP_API_URL` to that local server when testing on an emulator. Release builds disable cleartext traffic. Defaults point to example.invalid so an unconfigured build never sends reports to an unintended server.

See [setup and integrations](docs/SETUP.md), [API contract](docs/openapi.json), [permissions](docs/PERMISSIONS.md), and [architecture](docs/ARCHITECTURE.md).

## Browser smoke check

Local synthetic fixtures only; requires Chromium (or CDRMAPP_CHROMIUM pointing to a local executable).

```bash
docker compose -f infra/compose.yaml run --rm web php tests/Browser/seed.php
node infra/browser-smoke.cjs
```

The smoke check submits a conspicuously synthetic incident, marks it as test-only, verifies idempotency and agency isolation, exercises staff login/report detail and CMS publication, and writes docs/screenshots/staff-dashboard.png. It refuses a non-loopback target.
