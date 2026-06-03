# Refactor: mod_quiz\quiz_attempt

Tujuan refactoring ini adalah menaikkan Maintainability Index (MI) pada PhpMetrics dengan cara memecah `quiz_attempt` yang sangat besar menjadi beberapa trait kecil berbasis tanggung jawab.
untuk refactoring part 1 adalah file pertama refacoring sebelum final

## Cara pakai
1. Backup file asli `mod/quiz/classes/quiz_attempt.php`.
2. Letakkan `quiz_attempt.php` hasil refactor sebagai pengganti file asli.
3. Letakkan semua file `quiz_attempt_*_trait.php` di folder yang sama, yaitu `mod/quiz/classes/`.
4. Jalankan:
   - `php -l mod/quiz/classes/quiz_attempt.php`
   - `php -l mod/quiz/classes/quiz_attempt_*_trait.php`
   - test Moodle/PHPUnit terkait quiz attempt
   - `phpmetrics --report-html=report ./mod/quiz/classes`

## Catatan
- Public API tetap berada pada class `mod_quiz\quiz_attempt` karena method dipindahkan ke trait, bukan diganti nama.
- Refactor ini menargetkan MI dari sisi ukuran class, Logical Lines of Code, dan kompleksitas per abstraksi.
- Perlu regression test di environment Moodle karena file ini bergantung pada banyak class Moodle.
