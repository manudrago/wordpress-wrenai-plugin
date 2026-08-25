#!/usr/bin/env bash
# Run every suite. No WordPress, no dependencies: just PHP and Node.
set -e

cd "$(dirname "$0")/.."

php tests/test-sql-guard.php
echo
php tests/test-wren-payloads.php
echo
node tests/test-chart-renderer.js
