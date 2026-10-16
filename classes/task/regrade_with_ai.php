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

namespace tool_regradeaitextqs\task;

use tool_regradeaitextqs\regrader;
use tool_regradeaitextqs\result;

/**
 * Ask the AI again for stuck questions queued from the admin page or the scheduled task (each call can take seconds).
 *
 * Custom data: questionattemptids (int[]). The task runs as the admin who queued it (the main admin
 * when queued by the scheduled task), who is recorded as the grader. The capability and the attempt
 * (finished, not a preview) are checked again here, since both can change while the task waits.
 *
 * @package    tool_regradeaitextqs
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class regrade_with_ai extends \core\task\adhoc_task {
    /**
     * Regrade each queued question attempt that is still stuck.
     */
    public function execute(): void {
        global $DB, $USER;
        if (!has_capability('tool/regradeaitextqs:regrade', \context_system::instance())) {
            mtrace("User {$USER->id} may not regrade stuck AI Text questions; nothing was regraded.");
            return;
        }
        $data = $this->get_custom_data();
        $ids = array_map('intval', (array) ($data->questionattemptids ?? []));
        $regrader = new regrader();
        foreach ($ids as $qaid) {
            $row = $DB->get_record_sql(
                "SELECT qa.slot, quiza.id AS quizattemptid, quiza.state, quiza.preview
                   FROM {question_attempts} qa
                   JOIN {quiz_attempts} quiza ON quiza.uniqueid = qa.questionusageid
                  WHERE qa.id = :id",
                ['id' => $qaid]
            );
            if (!$row) {
                mtrace("Question attempt $qaid no longer exists.");
                continue;
            }
            if ($row->state !== \mod_quiz\quiz_attempt::FINISHED || (int) $row->preview) {
                mtrace("Question attempt $qaid is not in a finished, non-preview quiz attempt; skipped.");
                continue;
            }
            $attemptobj = \mod_quiz\quiz_attempt::create($row->quizattemptid);
            $outcome = $regrader->regrade($attemptobj, (int) $row->slot, [result::REREAD, result::ASKAI]);
            $mark = $outcome->category === result::GRADED ? ' mark ' . format_float($outcome->mark, 2) : '';
            mtrace("Question attempt $qaid: {$outcome->category}$mark. {$outcome->reason}");
        }
    }
}
