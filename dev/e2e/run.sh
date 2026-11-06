#!/usr/bin/env bash
# Browser end-to-end test (headless Chromium via Playwright) against http://localhost:8080.
# Requires ./setup.sh to have run. Screenshots end up in dev/e2e/shots/.
# Usage: dev/e2e/run.sh [script.py]   (default e2e.py; features_e2e.py tests files, turtle, exam mode, feedback)
set -euo pipefail
cd "$(dirname "$0")"
mkdir -p shots
(cd .. && docker compose exec -T -u www-data moodle php /mpl-dev/e2e/reset_user.php)
docker run --rm --network host -v "$PWD":/work -w /work mcr.microsoft.com/playwright/python:v1.48.0-jammy \
    bash -c "pip install -q playwright==1.48.0 2>/dev/null; python ${1:-e2e.py}"
