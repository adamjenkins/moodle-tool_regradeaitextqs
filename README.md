# Regrade stuck AI Text questions (`tool_regradeaitextqs`)

A Moodle admin tool that finds [AI Text](https://github.com/marcusgreen/moodle-qtype_aitext) questions left in **Requires grading** in finished quiz attempts, and grades them.

Before `qtype_aitext` 2026101400, an AI reply that was not valid JSON (for example `{feedback:"...","marks":3}`, copied from a malformed default prompt) was stored as the question's comment, with no mark, and the question stayed in "Requires grading" after the attempt was finished. Updating `qtype_aitext` stops new cases; this tool repairs the existing ones.

## Requirements

- Moodle 4.5 or later
- `qtype_aitext` 2026101400 or later (the version with the fixed feedback parser), with its behaviours `qbehaviour_immediate_for_aitext` and `qbehaviour_deferred_for_aitext`

## Installation

1. Copy the plugin directory into `<moodleroot>/public/admin/tool/regradeaitextqs/` (`<moodleroot>/admin/tool/regradeaitextqs/` before Moodle 5.1).
2. Visit Site administration → Notifications to run the upgrade.

## How it works

For each stuck AI Text question the tool decides one of four things, without calling the AI:

| What will happen | When |
|---|---|
| **Re-read stored AI reply** | The comment holds the AI's raw reply, and the current parser finds a mark in it. No AI call is needed. |
| **Ask the AI again** | The stored reply gives no mark (or none was stored). The prompt stored at grading time is sent to the AI again. |
| **Leave for the teacher** | The answer is empty or outside the word limits, so it was never sent to the AI. |
| **Not regraded** | Regrading was tried and the AI failed or replied without a mark again. The question is left as it was. |

A regraded question gets the mark the AI gave, scaled and clamped exactly as `qtype_aitext` does when grading, and the AI feedback (with the usual disclaimer) as its comment. The grade is recorded the way the quiz's own manual-grading page records it: the attempt total and the gradebook are updated, the "Question manually graded" event is logged, and the person running the tool is recorded as the grader.

### Admin page

Site administration → Plugins → Admin tools → **Regrade stuck AI Text questions** lists every quiz with stuck AI Text questions. **Review** opens one quiz: a table shows each stuck question, what will happen to it and, for re-reads, the new mark. Tick the rows and click **Regrade selected**: re-reads are graded at once; questions that need a new AI reply are queued as an adhoc task and graded when cron runs (each AI call can take several seconds).

Access needs the `tool/regradeaitextqs:regrade` capability (system context; managers by default).

### Command line

```
sudo -u www-data php admin/tool/regradeaitextqs/cli/regrade.php --quiz=ID
sudo -u www-data php admin/tool/regradeaitextqs/cli/regrade.php --course=ID --execute
sudo -u www-data php admin/tool/regradeaitextqs/cli/regrade.php --all --execute --no-ai
```

Without `--execute` nothing changes: each stuck question is listed with what would happen. `--execute` regrades, calling the AI one question at a time where needed; `--no-ai` limits it to re-reading stored replies. `--quiz` takes the quiz instance id. Grades are recorded as made by the main admin.

## Privacy

The tool stores no personal data. The grades and comments it writes are stored by the quiz and the question engine.

## Licence

GNU GPL v3 or later. Copyright 2026 Adam Jenkins. The mark calculation follows `qtype_aitext` by Marcus Green.
