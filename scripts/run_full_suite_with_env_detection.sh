#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOG_DIR="${ROOT_DIR}/docs/ami-client/production-readiness/execution"
mkdir -p "${LOG_DIR}"

STAMP="$(date -u +%Y%m%d-%H%M%SZ)"
LOG_FILE="${LOG_DIR}/${STAMP}-full-suite.log"

set +e
(
  cd "${ROOT_DIR}"
  vendor/bin/phpunit --colors=never
) 2>&1 | tee "${LOG_FILE}"
SUITE_EXIT="${PIPESTATUS[0]}"
set -e

CLASSIFICATION="$(php "${ROOT_DIR}/scripts/classify_test_environment_failure.php" --input="${LOG_FILE}")"

echo "classification=${CLASSIFICATION}"
echo "log_file=${LOG_FILE}"

if [[ "${SUITE_EXIT}" -ne 0 && "${CLASSIFICATION}" == "SANDBOX_ENVIRONMENT" ]]; then
  echo "outside_retry=required"
  echo "suggested_outside_command=vendor/bin/phpunit --colors=never"
fi

exit "${SUITE_EXIT}"
