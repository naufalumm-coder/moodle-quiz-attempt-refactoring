#!/usr/bin/env bash
set -euo pipefail

TARGET_DIR="${1:-refactored}"

php -l "$TARGET_DIR/quiz_attempt.php"
php -l "$TARGET_DIR/quiz_attempt_core_trait.php"
php -l "$TARGET_DIR/quiz_attempt_layout_trait.php"
php -l "$TARGET_DIR/quiz_attempt_getters_trait.php"
php -l "$TARGET_DIR/quiz_attempt_access_trait.php"
php -l "$TARGET_DIR/quiz_attempt_question_trait.php"
php -l "$TARGET_DIR/quiz_attempt_url_trait.php"
php -l "$TARGET_DIR/quiz_attempt_rendering_trait.php"
php -l "$TARGET_DIR/quiz_attempt_processing_trait.php"
php -l "$TARGET_DIR/quiz_attempt_event_trait.php"
php -l "$TARGET_DIR/quiz_attempt_question_update_trait.php"

echo "All PHP files passed syntax validation."
