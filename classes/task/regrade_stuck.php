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

use mod_quiz\quiz_attempt;
use tool_regradeaitextqs\defaults;
use tool_regradeaitextqs\finder;
use tool_regradeaitextqs\regrader;
use tool_regradeaitextqs\result;

/**
 * Apply the default actions to every stuck AI Text question on the site.
 *
 * Re-reads and invalid answers are graded in this run; questions that need a new AI reply are
 * queued as one regrade_with_ai adhoc task each (not queued twice while one is waiting). Runs as
 * the main admin, who is recorded as the grader.
 *
 * @package    tool_regradeaitextqs
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class regrade_stuck extends \core\task\scheduled_task {
    /**
     * Get the task name shown on the Scheduled tasks page.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_regradestuck', 'tool_regradeaitextqs');
    }

    /**
     * Grade or queue each stuck question as the default actions say.
     */
    public function execute(): void {
        $actions = defaults::actions();
        if (!$actions) {
            mtrace('Every default action is set to leave questions for the teacher; nothing to do.');
            return;
        }
        $regrader = new regrader();
        $attempts = [];
        $counts = [];
        foreach ((new finder())->find() as $qaid => $item) {
            $attemptobj = $attempts[$item->quizattemptid] ??= quiz_attempt::create($item->quizattemptid);
            $plan = $regrader->plan($attemptobj->get_question_attempt($item->slot), $actions);
            if ($plan->category === result::ASKAI) {
                $task = new regrade_with_ai();
                $task->set_custom_data(['questionattemptids' => [(int) $qaid]]);
                $outcome = \core\task\manager::queue_adhoc_task($task, true) ? 'queued' : 'already queued';
            } else if (in_array($plan->category, [result::REREAD, result::INVALID], true)) {
                $outcome = $regrader->regrade($attemptobj, (int) $item->slot, $actions)->category;
                // A graded question changes the attempt; load it again for its next slot.
                unset($attempts[$item->quizattemptid]);
            } else {
                $outcome = $plan->category;
            }
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
            mtrace("Question attempt $qaid: $outcome. {$plan->reason}");
        }
        ksort($counts);
        foreach ($counts as $outcome => $count) {
            mtrace("$outcome: $count");
        }
    }
}
