#!/usr/bin/env bash
#
# Run only the checks that cover what changed (`make verify`).
#
# Changed files = committed since the merge base with $BASE (default: main)
# + staged/unstaged + untracked (not ignored). The scope rules live in
# odd/tasks/faster-checks.md; in short:
#   backend/          backend-qa, api-check, backend-test narrowed to the touched
#                     contexts (Play -> Play; Studio -> Studio + Play; Admin -> Admin;
#                     anything else -> full PHPUnit). Behat runs inside backend-test.
#   frontend/         frontend-qa; Vitest `related` when only frontend/src changed,
#                     full Vitest for other files, none for e2e-only changes.
#   Makefile, compose*.yaml, docker/, scripts/, CI config -> everything, in full.
#   docs/, odd/, *.md -> nothing.
#
# CI always runs the full suites and stays the merge gate; this is the local shortcut.
#
# Usage: scripts/verify-changed.sh [--dry-run]
# Env:   BASE=main        branch to compare against
#        DRY_RUN=1        print the plan and exit (same as --dry-run)
#        VERIFY_FILES     newline/space separated repo-relative paths; replaces the git list (testing)

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

BASE="${BASE:-main}"
DRY_RUN="${DRY_RUN:-}"
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    *) echo "Unknown argument: $arg" >&2; exit 2 ;;
  esac
done

# --- changed files -----------------------------------------------------------

if [[ -n "${VERIFY_FILES+x}" ]]; then
  changed="$(printf '%s\n' "$VERIFY_FILES" | tr ' ' '\n')"
else
  merge_base="$(git merge-base "$BASE" HEAD)"
  changed="$(
    {
      git diff --name-only "$merge_base"...HEAD
      git diff --name-only HEAD
      git ls-files --others --exclude-standard
    }
  )"
fi
changed="$(printf '%s\n' "$changed" | sed '/^$/d' | sort -u)"

# --- classify ----------------------------------------------------------------

everything=0       # root tooling changed: run all in full
backend=0
backend_full=0     # PHPUnit cannot be narrowed
phpunit_dirs=()
frontend=0         # any frontend file that needs QA
vitest_full=0
vitest_related=()  # frontend/src files that still exist
api_check=0

add_dir() {
  local dir
  for dir in "$@"; do
    [[ -d "backend/$dir" ]] || continue
    [[ " ${phpunit_dirs[*]:-} " == *" $dir "* ]] || phpunit_dirs+=("$dir")
  done
}

add_context() {
  case "$1" in
    Play) add_dir tests/Unit/Play tests/Integration/Play ;;
    Studio) add_dir tests/Unit/Studio tests/Integration/Studio tests/Unit/Play tests/Integration/Play ;;
    Admin) add_dir tests/Unit/Admin tests/Integration/Admin ;;
    *) backend_full=1 ;;
  esac
}

while IFS= read -r file; do
  [[ -z "$file" ]] && continue
  case "$file" in
    docs/*|odd/*|*.md) ;;
    backend/*)
      backend=1
      api_check=1
      case "$file" in
        backend/src/*/*)
          rest="${file#backend/src/}"; add_context "${rest%%/*}" ;;
        backend/tests/Unit/*/*)
          rest="${file#backend/tests/Unit/}"; add_context "${rest%%/*}" ;;
        backend/tests/Integration/*/*)
          rest="${file#backend/tests/Integration/}"; add_context "${rest%%/*}" ;;
        backend/features/*/*)
          rest="${file#backend/features/}"
          ctx="${rest%%/*}"; add_context "$(tr '[:lower:]' '[:upper:]' <<<"${ctx:0:1}")${ctx:1}" ;;
        *) backend_full=1 ;;
      esac
      ;;
    frontend/e2e/*) ;;
    frontend/*)
      frontend=1
      case "$file" in
        frontend/src/shared/api/*) api_check=1 ;;
      esac
      if [[ "$file" == frontend/src/* ]]; then
        [[ -f "$file" ]] && vitest_related+=("${file#frontend/}")
      else
        vitest_full=1
      fi
      ;;
    *) everything=1 ;;
  esac
done <<<"$changed"

if ((everything)); then
  backend=1; backend_full=1; api_check=1; frontend=1; vitest_full=1
fi

# --- plan --------------------------------------------------------------------

steps=()
if ((backend)); then
  steps+=("make backend-qa")
  if ((backend_full || ${#phpunit_dirs[@]} == 0)); then
    steps+=("make backend-test")
  else
    steps+=("make backend-test PHPUNIT_ARGS=\"${phpunit_dirs[*]}\"")
  fi
fi
((api_check)) && steps+=("make api-check")
if ((frontend)); then
  steps+=("make frontend-qa")
  if ((vitest_full)); then
    steps+=("make frontend-test")
  elif ((${#vitest_related[@]})); then
    steps+=("make frontend-test-related FILES=\"${vitest_related[*]}\"")
  fi
fi

if ((${#steps[@]} == 0)); then
  echo "Nothing to verify."
  exit 0
fi

echo "Changed since $BASE: $(printf '%s\n' "$changed" | wc -l | tr -d ' ') file(s). Plan:"
printf '  %s\n' "${steps[@]}"
[[ -n "$DRY_RUN" ]] && exit 0

# --- run every step, then summarise ------------------------------------------

summary=()
failed=0
for step in "${steps[@]}"; do
  echo
  echo "==> $step"
  start=$SECONDS
  if eval "$step"; then status=pass; else status=FAIL; failed=1; fi
  summary+=("$(printf '%-4s %4ss  %s' "$status" "$((SECONDS - start))" "$step")")
done

echo
echo "Summary:"
printf '  %s\n' "${summary[@]}"
exit "$failed"
