#!/usr/bin/env bash
#
# Tests for scripts/db-restore.sh (`make verify-test`).
#
# The script talks to Docker only through $DOCKER_COMPOSE, so these cases use a fake
# compose executable that logs its arguments and never touches a real database.
# Fake knobs (env): FAKE_RUNNING=0 (database service down), FAKE_FAIL_LIST=1
# (`pg_restore -l` fails), FAKE_FAIL_RESTORE=1 (the real `pg_restore` fails).

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

log="$tmp/calls.log"
fake="$tmp/compose"
cat >"$fake" <<'FAKE'
#!/usr/bin/env bash
echo "$*" >>"$FAKE_LOG"
[[ -t 0 ]] || cat >/dev/null
case "$*" in
  *"ps --services"*) [[ "${FAKE_RUNNING:-1}" == 1 ]] && echo database ;;
  *"printenv POSTGRES_USER"*) echo ogami ;;
  *"printenv POSTGRES_DB"*) echo ogami ;;
  *"pg_restore -l"*) [[ "${FAKE_FAIL_LIST:-0}" == 1 ]] && exit 1 ;;
  *"pg_restore "*) [[ "${FAKE_FAIL_RESTORE:-0}" == 1 ]] && exit 1 ;;
esac
exit 0
FAKE
chmod +x "$fake"

dump="$tmp/test.dump"
echo "not a real dump" >"$dump"
(cd "$tmp" && sha256sum test.dump >test.dump.sha256)

passed=0
failed=0
code=0

pass() { echo "pass  $1"; passed=$((passed + 1)); }
fail() { echo "FAIL  $1: $2"; failed=$((failed + 1)); }

# run <env assignments...>: run the script with the fake compose, stdin from /dev/null.
run() {
  : >"$log"
  code=0
  env DOCKER_COMPOSE="$fake" FAKE_LOG="$log" "$@" scripts/db-restore.sh </dev/null >"$tmp/out" 2>&1 || code=$?
}

# expect <name> <exit code> [forbidden log pattern...]
expect() {
  local name="$1" want="$2" pattern
  shift 2
  if [[ "$code" != "$want" ]]; then
    fail "$name" "exit $code, expected $want"
    return
  fi
  for pattern in "$@"; do
    if grep -q -- "$pattern" "$log"; then
      fail "$name" "unexpected call matching '$pattern'"
      return
    fi
  done
  pass "$name"
}

run FILE=
expect "no FILE" 2 "."
[[ ! -s "$log" ]] && pass "no FILE makes no compose call" || fail "no FILE makes no compose call" "log not empty"

run FILE="$tmp/missing.dump"
expect "unreadable FILE" 2 "."

run FILE="$dump" DB='bad-name;drop'
expect "invalid DB" 2 "."

bad="$tmp/bad.dump"
echo "tampered" >"$bad"
echo "0000000000000000000000000000000000000000000000000000000000000000  bad.dump" >"$bad.sha256"
run FILE="$bad" FORCE=1
expect "bad checksum" 1 "createdb" "dropdb"

run FILE="$dump" FORCE=1 FAKE_RUNNING=0
expect "database not running" 1 "createdb" "dropdb"

run FILE="$dump" FORCE=1 FAKE_FAIL_LIST=1
expect "invalid archive" 1 "createdb" "dropdb"

run FILE="$dump"
expect "non-terminal stdin without FORCE" 1 "createdb" "dropdb"

run FILE="$dump" FORCE=1
expect "FORCE=1 happy path exit" 0
# Line numbers of each step in the call log must be strictly increasing.
steps=('createdb -U ogami ogami_restoring' 'pg_restore -U ogami --exit-on-error --no-owner -d ogami_restoring' 'dropdb -U ogami --force --if-exists ogami$' 'RENAME TO "ogami"' 'doctrine:migrations:migrate')
prev=0 ordered=1
for step in "${steps[@]}"; do
  n="$(grep -n -- "$step" "$log" | head -1 | cut -d: -f1)"
  if [[ -z "$n" || "$n" -le "$prev" ]]; then ordered=0; break; fi
  prev="$n"
done
((ordered)) && pass "happy path order" || fail "happy path order" "$(tr '\n' '|' <"$log")"

run FILE="$dump" FORCE=1 FAKE_FAIL_RESTORE=1
expect "restore failure exit" 1 "dropdb --force" "RENAME" "migrations:migrate"
grep -q 'dropdb .*--if-exists.* ogami_restoring' "$log" && pass "restore failure drops tmp" || fail "restore failure drops tmp" "no tmp dropdb"

run FILE="$dump" FORCE=1 DB=other
expect "DB=other skips migrations" 0 "migrations:migrate"
grep -q 'RENAME TO "other"' "$log" && pass "DB=other renames to other" || fail "DB=other renames to other" "no rename"

# Typed confirmation needs a terminal: use script(1) to provide one.
if command -v script >/dev/null; then
  tty_run() { # tty_run <typed answer>
    : >"$log"
    code=0
    printf '%s\n' "$1" | script -qec "env DOCKER_COMPOSE='$fake' FAKE_LOG='$log' FILE='$dump' scripts/db-restore.sh" /dev/null >"$tmp/out" 2>&1 || code=$?
  }
  tty_run ogami
  expect "typed correct name" 0 "XXXX"
  grep -q 'RENAME TO "ogami"' "$log" && pass "typed correct name restores" || fail "typed correct name restores" "no rename"
  tty_run wrong
  expect "typed wrong name aborts" 1 "createdb" "dropdb"
else
  echo "skip  typed confirmation (script(1) not available): untested"
fi

echo
echo "$passed passed, $failed failed"
((failed == 0))
