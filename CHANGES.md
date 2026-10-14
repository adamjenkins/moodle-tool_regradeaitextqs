# Release notes — 0.1.0

First release.

- Finds AI Text questions left in "Requires grading" in finished quiz attempts.
- Re-reads the stored AI reply with the fixed `qtype_aitext` parser (requires `qtype_aitext` 2026101400), and asks the AI again where the stored reply gives no mark.
- Answers that never reached the AI (empty or outside the word limits) are listed and left for the teacher.
- Grades are recorded like a manual grade on the quiz's grading page: attempt total, gradebook and "Question manually graded" event.
- Admin page (per-quiz preview, regrade selected; AI regrades run as an adhoc task) and `cli/regrade.php` (dry run by default, `--execute`, `--no-ai`).
