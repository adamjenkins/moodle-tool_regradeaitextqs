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
$string['backtolist'] = 'Back to all quizzes';
$string['category_askai'] = 'Ask the AI again';
$string['category_failed'] = 'Not regraded';
$string['category_graded'] = 'Graded';
$string['category_reread'] = 'Re-read stored AI reply';
$string['category_teacher'] = 'Leave for the teacher';
$string['clidryrun'] = 'Dry run: nothing was changed. Add --execute to regrade.';
$string['intro'] = 'AI Text questions in finished quiz attempts can be left in "Requires grading" when the AI reply could not be read (for example, malformed JSON shown as the comment). This tool finds them, re-reads the stored AI reply with the current parser, and asks the AI again where the stored reply gives no mark. Answers that never reached the AI, such as those under the word minimum, are left for the teacher.';
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
$string['regradehelp'] = 'Ticked questions with a stored reply are graded now. Ticked questions that need a new AI reply are queued and graded when cron runs. The grade is recorded as a manual grade by you, with the AI feedback as the comment.';
$string['regradeselected'] = 'Regrade selected';
$string['review'] = 'Review';
$string['selectrow'] = 'Regrade this question';
$string['stuckcount'] = 'Questions waiting for grading';
$string['student'] = 'Student';
