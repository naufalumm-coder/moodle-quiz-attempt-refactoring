# How to Apply the Refactoring to Moodle 4.4.12+

## 1. Backup

From the Moodle root directory:

```bash
cp mod/quiz/classes/quiz_attempt.php mod/quiz/classes/quiz_attempt_original.php
```

## 2. Copy Refactored Files

Copy all files from this repository's `refactored/` folder into:

```text
mod/quiz/classes/
```

## 3. Syntax Check

Run:

```bash
php -l mod/quiz/classes/quiz_attempt.php
php -l mod/quiz/classes/quiz_attempt_core_trait.php
php -l mod/quiz/classes/quiz_attempt_layout_trait.php
php -l mod/quiz/classes/quiz_attempt_getters_trait.php
php -l mod/quiz/classes/quiz_attempt_access_trait.php
php -l mod/quiz/classes/quiz_attempt_question_trait.php
php -l mod/quiz/classes/quiz_attempt_url_trait.php
php -l mod/quiz/classes/quiz_attempt_rendering_trait.php
php -l mod/quiz/classes/quiz_attempt_processing_trait.php
php -l mod/quiz/classes/quiz_attempt_event_trait.php
php -l mod/quiz/classes/quiz_attempt_question_update_trait.php
```

## 4. Purge Cache

```bash
php admin/cli/purge_caches.php
```

## 5. Functional Validation

Minimum validation scenario:

1. Open or create a quiz.
2. Start an attempt as a student.
3. Navigate between quiz pages.
4. Save answers.
5. Submit the attempt.
6. Open the attempt summary page.
7. Open the review page.
8. Check the grade/report display.

## 6. Rollback

If an error occurs, restore the original file:

```bash
cp mod/quiz/classes/quiz_attempt_original.php mod/quiz/classes/quiz_attempt.php
php admin/cli/purge_caches.php
```
