#!/usr/bin/env bash
#
# Restore the dev PostgreSQL database from a `pg_dump -Fc` file (`make db-restore`).
#
# Order: validate input -> verify the optional <FILE>.sha256 -> check the archive is
# readable -> confirm -> restore into <target>_restoring -> only then drop the target and
# rename the new database into its place. A bad dump therefore never touches the target.
# When the target is the dev database, pending migrations run afterwards.
#
# Dumps contain password hashes and personal data: keep them private, never commit them.
#
# Usage: scripts/db-restore.sh   (configured through the environment, see below)
# Env:   FILE=<dump>      required, a custom-format dump (`pg_dump -Fc`)
#        DB=<name>        restore into this database instead of the dev one (letters, digits, _)
#        FORCE=1          skip the typed confirmation (required when stdin is not a terminal)
#        DOCKER_COMPOSE   compose command, default `docker compose` (expanded unquoted)

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

FILE="${FILE:-}"
DB="${DB:-}"
FORCE="${FORCE:-}"
DOCKER_COMPOSE="${DOCKER_COMPOSE:-docker compose}"

usage() {
  echo "Usage: make db-restore FILE=<dump> [DB=<name>] [FORCE=1]" >&2
  exit 2
}

# shellcheck disable=SC2086 # DOCKER_COMPOSE may be several words
compose() { $DOCKER_COMPOSE "$@"; }

[[ -n "$FILE" && -r "$FILE" && -f "$FILE" ]] || { echo "FILE is missing or not readable: '${FILE}'" >&2; usage; }
if [[ -n "$DB" && ! "$DB" =~ ^[A-Za-z0-9_]+$ ]]; then
  echo "DB must match [A-Za-z0-9_]+: '$DB'" >&2
  usage
fi

if [[ -f "$FILE.sha256" ]]; then
  (cd "$(dirname "$FILE")" && sha256sum -c "$(basename "$FILE").sha256") >/dev/null \
    || { echo "Checksum verification failed for $FILE" >&2; exit 1; }
else
  echo "Warning: $FILE.sha256 not found, skipping checksum verification" >&2
fi

# Captured first: under pipefail, grep -q closing the pipe early could fail the pipeline.
running="$(compose ps --services --status running)"
if ! grep -qx database <<<"$running"; then
  echo "The database service is not running: run make up first." >&2
  exit 1
fi

user="$(compose exec -T database printenv POSTGRES_USER)"
dev_db="$(compose exec -T database printenv POSTGRES_DB)"
target="${DB:-$dev_db}"
tmp="${target}_restoring"

if ! compose exec -T database pg_restore -l <"$FILE" >/dev/null; then
  echo "$FILE is not a readable pg_dump custom-format archive." >&2
  exit 1
fi

if [[ "$FORCE" != 1 ]]; then
  if [[ ! -t 0 ]]; then
    echo "Refusing to replace '$target' without a terminal to confirm: pass FORCE=1." >&2
    exit 1
  fi
  echo "This replaces ALL data in the database '$target' with the contents of $FILE."
  read -r -p "Type the database name ($target) to continue: " answer
  [[ "$answer" == "$target" ]] || { echo "Aborted, nothing changed." >&2; exit 1; }
fi

compose exec -T database dropdb -U "$user" --if-exists "$tmp"
compose exec -T database createdb -U "$user" "$tmp"
if ! compose exec -T database pg_restore -U "$user" --exit-on-error --no-owner -d "$tmp" <"$FILE"; then
  compose exec -T database dropdb -U "$user" --if-exists "$tmp" || true
  echo "Restore failed: '$target' is untouched." >&2
  exit 1
fi

compose exec -T database dropdb -U "$user" --force --if-exists "$target"
compose exec -T database psql -U "$user" -d postgres -v ON_ERROR_STOP=1 \
  -c "ALTER DATABASE \"$tmp\" RENAME TO \"$target\""

if [[ "$target" == "$dev_db" ]]; then
  compose exec -T php php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration \
    || { echo "Data was restored into '$target' but the migrations failed." >&2; exit 1; }
else
  echo "Skipped migrations: '$target' is not the dev database ($dev_db)."
fi

echo "Restored '$target' from $FILE."
