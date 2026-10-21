#!/usr/bin/env bash
#
# Table-driven tests for scripts/verify-changed.sh (`make verify-test`).
#
# Each case feeds VERIFY_FILES to a dry run and compares the printed plan lines
# (the "Changed since" header with its file count is dropped) with the expected ones.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

passed=0
failed=0

# check <name> <VERIFY_FILES> <expected plan lines, newline separated>
check() {
  local name="$1" files="$2" expected="$3" actual
  actual="$(VERIFY_FILES="$files" DRY_RUN=1 scripts/verify-changed.sh 2>/dev/null | grep -v '^Changed since ' | sed 's/^  //')" || true
  if [[ "$actual" == "$expected" ]]; then
    echo "pass  $name"
    passed=$((passed + 1))
  else
    echo "FAIL  $name"
    # shellcheck disable=SC2001 # prefixing every line is clearer with sed
    printf '  expected:\n%s\n  actual:\n%s\n' "$(sed 's/^/    /' <<<"$expected")" "$(sed 's/^/    /' <<<"$actual")"
    failed=$((failed + 1))
  fi
}

play='make backend-qa
make backend-test PHPUNIT_ARGS="tests/Unit/Play tests/Integration/Play"
make api-check'
studio='make backend-qa
make backend-test PHPUNIT_ARGS="tests/Unit/Studio tests/Integration/Studio tests/Unit/Play tests/Integration/Play"
make api-check'
everything='make verify-test
make backend-qa
make backend-test
make api-check
make frontend-qa
make frontend-test'
vitest_full='make frontend-qa
make frontend-test'

check "docs only" "docs/vision.md odd/tasks/x.md README.md" "Nothing to verify."
check "Play src" "backend/src/Play/Domain/X.php" "$play"
check "Studio src" "backend/src/Studio/Domain/X.php" "$studio"
check "Admin src" "backend/src/Admin/Domain/X.php" 'make backend-qa
make backend-test PHPUNIT_ARGS="tests/Integration/Admin"
make api-check'
check "Shared src" "backend/src/Shared/X.php" 'make backend-qa
make backend-test
make api-check'
check "Behat context" "backend/tests/Behat/PlayContext.php" 'make backend-qa
make backend-test
make api-check'
check "Behat feature" "backend/features/play/x.feature" "$play"
check "frontend src file" "frontend/src/main.tsx" 'make frontend-qa
make frontend-test-related FILES="src/main.tsx"'
# shellcheck disable=SC2016 # the literal $ and backticks are the point
check "frontend src path with dollar" 'frontend/src/main$x.tsx' "$vitest_full"
check "frontend src path with quote" "frontend/src/main'x.tsx" "$vitest_full"
# shellcheck disable=SC2016
check "frontend src path with backtick" 'frontend/src/main`x`.tsx' "$vitest_full"
check "frontend setup file" "frontend/src/test/setup.ts" "$vitest_full"
check "deleted frontend src file" "frontend/src/play/DoesNotExist.tsx" "$vitest_full"
check "frontend package.json" "frontend/package.json" "$vitest_full"
check "e2e only" "frontend/e2e/smoke.spec.ts" "make frontend-qa"
check "schema.d.ts" "frontend/src/shared/api/schema.d.ts" 'make api-check
make frontend-qa
make frontend-test-related FILES="src/shared/api/schema.d.ts"'
check "Makefile" "Makefile" "$everything"
check "scripts" "scripts/verify-changed.sh" "$everything"
# Play was added first, so its directories come first
check "rename pair" "backend/src/Play/A.php backend/src/Studio/A.php" 'make backend-qa
make backend-test PHPUNIT_ARGS="tests/Unit/Play tests/Integration/Play tests/Unit/Studio tests/Integration/Studio"
make api-check'

echo
echo "$passed passed, $failed failed"
((failed == 0))
