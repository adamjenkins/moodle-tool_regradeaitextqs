<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * English strings for tool_regradeaitextqs.
 *
 * @package    tool_regradeaitextqs
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['action'] = 'What will happen';
$string['action_askai'] = 'Questions that need a new AI reply';
$string['action_askai_act'] = 'Ask the AI again';
$string['action_askai_desc'] = 'The stored AI reply gives no mark, or none was stored. Asking again sends the prompt stored at grading time to the AI; the scheduled task and the review page queue these as adhoc tasks, graded when cron runs.';
$string['action_invalid'] = 'Answers that never reached the AI';
$string['action_invalid_act'] = 'Give a mark of zero';
$string['action_invalid_desc'] = 'The answer is empty or outside the word limits, so it was never sent to the AI. A mark of zero is recorded with the invalid-answer feedback below as the comment.';
$string['action_reread'] = 'Questions with a readable stored AI reply';
$string['action_reread_act'] = 'Grade from the stored reply';
$string['action_reread_desc'] = 'The stored AI reply gives a mark when read with the current parser. No AI call is needed.';
$string['backtolist'] = 'Back to all quizzes';
$string['category_askai'] = 'Ask the AI again';
$string['category_failed'] = 'Not regraded';
$string['category_graded'] = 'Graded';
$string['category_invalid'] = 'Give a mark of zero (invalid answer)';
$string['category_reread'] = 'Re-read stored AI reply';
$string['category_teacher'] = 'Leave for the teacher';
$string['clidryrun'] = 'Dry run: nothing was changed. Add --execute to regrade.';
$string['defaultactions'] = 'Default actions';
$string['defaultactions_desc'] = 'What the tool does with each kind of stuck question when the scheduled task "{$a->task}" runs and when cli/regrade.php is run with --execute. On the review page, rows whose action is to leave them for the teacher start unticked. The scheduled task is disabled when the plugin is installed: enable it on the <a href="{$a->url}">Scheduled tasks</a> page.';
$string['intro'] = 'AI Text questions in finished quiz attempts can be left in "Requires grading" when the AI reply could not be read (for example, malformed JSON shown as the comment). This tool finds them, re-reads the stored AI reply with the current parser, and asks the AI again where the stored reply gives no mark. Answers that never reached the AI, such as those under the word minimum, are given a mark of zero with feedback that the answer was invalid.';
$string['invalidfeedback'] = 'Invalid-answer feedback';
$string['invalidfeedback_default'] = 'Your answer was empty or outside the word limits for this question, so it could not be graded. It has been given a mark of zero.';
$string['invalidfeedback_desc'] = 'The comment stored with the zero mark for an answer that never reached the AI. Plain text.';
$string['newmark'] = 'New mark';
$string['nonestuck'] = 'No AI Text questions are waiting for grading in finished quiz attempts.';
$string['nonestuckquiz'] = 'No AI Text questions in this quiz are waiting for grading.';
$string['pluginname'] = 'Regrade stuck AI Text questions';
$string['privacy:metadata'] = 'The Regrade stuck AI Text questions tool stores no personal data. The grades and comments it records are stored by the quiz and the question engine.';
$string['reason'] = 'Details';
$string['reason_aifailed'] = 'The AI request failed: {$a}';
$string['reason_ainomarks'] = 'The AI replied again without a mark.';
$string['reason_askai_nomarks'] = 'The stored AI reply gives no mark.';
$string['reason_askai_noreply'] = 'No AI reply was stored for this answer.';
$string['reason_askedai'] = 'Graded from a new AI reply.';
$string['reason_incomplete'] = 'The answer is empty or outside the word limits, so it was never sent to the AI.';
$string['reason_notaitext'] = 'This is not an AI Text question.';
$string['reason_notstuck'] = 'The question is no longer waiting for grading.';
$string['reason_reread'] = 'The stored AI reply gives a mark when read with the current parser.';
$string['regradeaitextqs:regrade'] = 'Regrade stuck AI Text questions';
$string['regradedone'] = 'Graded: {$a->graded}. Queued to ask the AI again (runs with cron): {$a->queued}. Not regraded: {$a->skipped}.';
$string['regradehelp'] = 'Ticked questions with a stored reply, and ticked invalid answers, are graded now. Ticked questions that need a new AI reply are queued and graded when cron runs. The grade is recorded as a manual grade by you, with the AI feedback (or the invalid-answer feedback) as the comment. Which rows start ticked follows the default actions.';
$string['regradeselected'] = 'Regrade selected';
$string['review'] = 'Review';
$string['selectrow'] = 'Regrade this question';
$string['stuckcount'] = 'Questions waiting for grading';
$string['stuckquestions'] = 'Stuck questions';
$string['student'] = 'Student';
$string['task_regradestuck'] = 'Regrade stuck AI Text questions with the default actions';
