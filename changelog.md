# Changelog

## 0.1.0 (2026-10-14)

First release. Finds AI Text questions left in "Requires grading" in finished quiz attempts and grades them: re-reads the stored AI reply with the fixed `qtype_aitext` parser, asks the AI again where the stored reply gives no mark, and leaves answers that never reached the AI for the teacher. Admin page with a per-quiz preview, adhoc task for AI regrades, and a CLI script with a dry-run default.
