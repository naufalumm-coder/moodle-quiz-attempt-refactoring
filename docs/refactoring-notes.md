# Refactoring Notes

## Background

The target file `quiz_attempt.php` in Moodle `mod_quiz` contains the class `mod_quiz\quiz_attempt`. Before refactoring, the file contained many responsibilities in one class, including attempt state handling, question layout, rendering, URL generation, review access, event handling, and attempt processing.

## Refactoring Stage 1

The first stage focused on local readability improvements:

- Shortening long `if-else` logic.
- Reducing nested conditional blocks.
- Using guard clauses to make exit conditions explicit.

Metric result:

- MI increased from 43.08 to 43.97.

This stage improved readability but did not substantially reduce the large-file problem.

## Refactoring Stage 2

The second stage focused on structural decomposition:

- The main class name `mod_quiz\quiz_attempt` was preserved.
- Public behavior was preserved.
- Methods were grouped into responsibility-based traits.
- The main `quiz_attempt.php` file became a smaller class composition file.

Generated trait groups:

- `quiz_attempt_core_trait.php`
- `quiz_attempt_layout_trait.php`
- `quiz_attempt_getters_trait.php`
- `quiz_attempt_access_trait.php`
- `quiz_attempt_question_trait.php`
- `quiz_attempt_url_trait.php`
- `quiz_attempt_rendering_trait.php`
- `quiz_attempt_processing_trait.php`
- `quiz_attempt_event_trait.php`
- `quiz_attempt_question_update_trait.php`

Metric result:

- MI increased to 96.08.
- CC of the main file decreased from 178 to 2.
- LOC of the main file decreased from approximately 2,049 to approximately 154.

## Important Interpretation Note

The final MI result should be interpreted carefully. The high MI value mainly reflects the maintainability of the main file after decomposition. For a complete module-level claim, the generated trait files should also be measured using PhpMetrics and discussed as an aggregate component.
