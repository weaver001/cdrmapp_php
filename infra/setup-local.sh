#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
export npm_config_cache="${npm_config_cache:-/workspace/.cache/cdrmapp-npm}"
export DOCKER_CONFIG="${DOCKER_CONFIG:-/workspace/.cache/cdrmapp-docker}"
python3 - <<'SETUP'
from pathlib import Path
import secrets
p=Path('infra/.env')
if not p.exists():
    p.write_text('DB_PASSWORD='+secrets.token_hex(24)+'\nDB_ROOT_PASSWORD='+secrets.token_hex(24)+'\n')
    p.chmod(0o600)
p=Path('backend/.env')
if not p.exists():
    p.write_text(Path('backend/.env.example').read_text())
    p.chmod(0o600)
SETUP
docker compose -f infra/compose.yaml build
# The cloud CA may be needed by Composer behind the managed proxy.
if [[ -n "${SSL_CERT_FILE:-}" && -f "$SSL_CERT_FILE" ]]; then
  ca_args=(-v "$SSL_CERT_FILE:/tmp/cloud-ca.pem:ro" -e COMPOSER_CAFILE=/tmp/cloud-ca.pem)
else
  ca_args=()
fi
cache_dir="${CDRMAPP_COMPOSER_CACHE:-/workspace/.cache/cdrmapp-composer}"
mkdir -p "$cache_dir"
docker run --rm -u 1000:1000 -e HTTPS_PROXY -e HTTP_PROXY -e https_proxy -e http_proxy -e NO_PROXY -e no_proxy "${ca_args[@]}" -e COMPOSER_HOME=/tmp/composer -e COMPOSER_CACHE_DIR=/cache -v "$cache_dir:/cache" -v "$PWD/backend:/app" -w /app composer:2@sha256:d0c32820c62cc860fddd22adceed4eeda7698b093a7a88d503414172ce400626 composer install --no-interaction --prefer-dist
if ! rg -q '^APP_KEY=base64:' backend/.env; then
  docker compose -f infra/compose.yaml run --rm --no-deps web php artisan key:generate
fi
docker compose -f infra/compose.yaml up -d --wait db mailpit
docker compose -f infra/compose.yaml run --rm web php artisan migrate --force
docker compose -f infra/compose.yaml run --rm web php artisan db:seed --force
if [[ ! -L backend/public/storage ]]; then
 if [[ -e backend/public/storage ]]; then
  echo 'backend/public/storage exists and is not a symlink; preserving it.' >&2
  exit 1
 fi
 docker compose -f infra/compose.yaml run --rm web php artisan storage:link
fi
npm --prefix backend ci
npm --prefix backend run build
docker compose -f infra/compose.yaml up -d web queue
curl --fail --retry-all-errors --retry 15 --retry-connrefused --retry-delay 1 http://127.0.0.1:8000/up
