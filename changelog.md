# Changelog

## Unreleased

- Answers that never reached the AI (empty or outside the word limits) are given a mark of zero, with settable feedback saying the answer was invalid, instead of being left for the teacher.
- Default-action settings: for each kind of stuck question, act on it or leave it for the teacher. Used by the scheduled task, `cli/regrade.php --execute` and the review page's ticked rows.
- The adhoc AI-regrade task checks the capability again when it runs, and skips question attempts that are not in a finished, non-preview quiz attempt.
- New scheduled task (disabled by default) that applies the default actions site-wide and queues one adhoc task per question that needs a new AI reply.

## 0.1.0 (2026-10-14)

First release. Finds AI Text questions left in "Requires grading" in finished quiz attempts and grades them: re-reads the stored AI reply with the fixed `qtype_aitext` parser, asks the AI again where the stored reply gives no mark, and leaves answers that never reached the AI for the teacher. Admin page with a per-quiz preview, adhoc task for AI regrades, and a CLI script with a dry-run default.
