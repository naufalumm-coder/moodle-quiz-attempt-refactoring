# Moodle `quiz_attempt.php` Refactoring Artefact

This repository contains the refactoring artefact used for an academic study on improving the Maintainability Index of the Moodle `mod_quiz` component using PhpMetrics.

## Project Scope

- **Platform**: Moodle 4.4.12+
- **Module**: `mod_quiz`
- **Target file**: `mod/quiz/classes/quiz_attempt.php`
- **Target class**: `mod_quiz\quiz_attempt`
- **Main analysis tool**: PhpMetrics
- **Main metrics**: Maintainability Index, Cyclomatic Complexity, Lines of Code, and Halstead Volume

The refactoring focuses on the internal organization of the `quiz_attempt` class. The public class name and public method access are preserved to maintain compatibility with Moodle's existing call structure.

## Refactoring Summary

The refactoring was conducted in two stages:

1. **Stage 1 — Conditional simplification**
   - Simplified long `if-else` structures.
   - Reduced nested conditional logic using guard clauses.
   - Improved local readability in selected methods.

2. **Stage 2 — Structural decomposition**
   - Split the large `quiz_attempt.php` file into several responsibility-based traits.
   - Grouped methods by functional area, such as layout handling, access control, question processing, rendering, URL generation, event handling, and attempt processing.
   - Preserved the main class `mod_quiz\quiz_attempt` as the public entry point.

## Metrics Summary

| Metric | Before Refactoring | After Stage 1 | After Stage 2 |
|---|---:|---:|---:|
| Maintainability Index | 43.08 | 43.97 | 96.08 |
| Cyclomatic Complexity of main file | 178 | To be filled from PhpMetrics | 2 |
| LOC of main file | ~2,049 | To be filled from PhpMetrics | ~154 |
| Main technique | Baseline | Guard Clause, Decompose Conditional | Extract Trait, Extract Method |

> Note: The final MI value mainly represents the maintainability of the main target file after structural decomposition. Since part of the implementation is moved into traits, an aggregate PhpMetrics report should also be included when discussing the maintainability of the complete refactored component.

## Repository Structure

```text
.
├── original/
│   └── quiz_attempt.php
├── refactored/
│   ├── quiz_attempt.php
│   ├── quiz_attempt_core_trait.php
│   ├── quiz_attempt_layout_trait.php
│   ├── quiz_attempt_getters_trait.php
│   ├── quiz_attempt_access_trait.php
│   ├── quiz_attempt_question_trait.php
│   ├── quiz_attempt_url_trait.php
│   ├── quiz_attempt_rendering_trait.php
│   ├── quiz_attempt_processing_trait.php
│   ├── quiz_attempt_event_trait.php
│   └── quiz_attempt_question_update_trait.php
├── metrics/
│   ├── before/
│   ├── after-stage-1/
│   └── after-stage-2/
├── screenshots/
├── docs/
└── scripts/
```

## How to Apply the Refactored Files

1. Back up the original Moodle file:

```bash
cp mod/quiz/classes/quiz_attempt.php mod/quiz/classes/quiz_attempt_original.php
```

2. Copy all files from the `refactored/` directory into:

```text
mod/quiz/classes/
```

3. Run PHP syntax checks:

```bash
bash scripts/check_syntax.sh
```

4. Purge Moodle caches:

```bash
php admin/cli/purge_caches.php
```

5. Run functional validation for the quiz workflow:
   - Create or open a quiz.
   - Start an attempt.
   - Navigate between question pages.
   - Save answers.
   - Submit the attempt.
   - Open summary and review pages.

## PhpMetrics Commands

Example commands:

```bash
phpmetrics --report-html=metrics/before/phpmetrics-report original/quiz_attempt.php
phpmetrics --report-html=metrics/after-stage-2/phpmetrics-report refactored/quiz_attempt.php refactored/quiz_attempt_*_trait.php
```

For a journal manuscript, attach the PhpMetrics screenshots or generated HTML reports in the `metrics/` and `screenshots/` folders.

## Academic Use

This repository is intended as a research artefact for a study on source-code maintainability improvement through refactoring. It may be cited in the manuscript as the artefact repository that contains the before-after code and supporting metric evidence.

## License Notice

This repository contains modified Moodle source code for academic research purposes. Moodle is distributed under the GNU General Public License version 3 or later. Original Moodle copyright and license notices are preserved in the source files.
