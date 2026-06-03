#!/usr/bin/env bash
set -euo pipefail

phpmetrics --report-html=metrics/before/phpmetrics-report original/quiz_attempt.php
phpmetrics --report-html=metrics/after-stage-2/phpmetrics-report refactored/quiz_attempt.php refactored/quiz_attempt_*_trait.php

echo "PhpMetrics reports generated under metrics/."
