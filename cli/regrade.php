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
 * CLI: list or regrade AI Text questions stuck in "Requires grading" in finished quiz attempts.
 *
 * @package    tool_regradeaitextqs
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use tool_regradeaitextqs\defaults;
use tool_regradeaitextqs\finder;
use tool_regradeaitextqs\regrader;
use tool_regradeaitextqs\result;

[$options, $unrecognised] = cli_get_params(
    ['course' => 0, 'quiz' => 0, 'all' => false, 'execute' => false, 'no-ai' => false, 'help' => false],
    ['h' => 'help']
);
if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}

$help = "List or regrade AI Text questions left in \"Requires grading\" in finished quiz attempts.

Without --execute nothing is changed: each stuck question is listed with what would happen.
What happens to each kind of stuck question follows the plugin's default-action settings.

Options:
  --course=ID   Only quizzes in this course.
  --quiz=ID     Only this quiz (quiz instance id).
  --all         Every quiz on the site.
  --execute     Regrade: re-read stored AI replies, ask the AI again where they give no mark,
                and give answers that never reached the AI a mark of zero.
  --no-ai       Never call the AI (questions that need it are left as they are).
  -h, --help    Print this help.

Example:
  sudo -u www-data php admin/tool/regradeaitextqs/cli/regrade.php --quiz=69 --execute
";

$courseid = (int) $options['course'];
$quizid = (int) $options['quiz'];
if ($options['help'] || (!$courseid && !$quizid && !$options['all'])) {
    echo $help;
    exit(0);
}

// Graded steps record this user as the grader.
\core\session\manager::set_user(get_admin());

$items = (new finder())->find($courseid ?: null, $quizid ?: null);
if (!$items) {
    cli_writeln(get_string('nonestuck', 'tool_regradeaitextqs'));
    exit(0);
}

$actions = defaults::actions();
if ($options['no-ai']) {
    $actions = array_values(array_diff($actions, [result::ASKAI]));
}
$regrader = new regrader();
$counts = [];
$attempts = [];
foreach ($items as $qaid => $item) {
    if (!isset($attempts[$item->quizattemptid])) {
        $attempts[$item->quizattemptid] = \mod_quiz\quiz_attempt::create($item->quizattemptid);
    }
    $attemptobj = $attempts[$item->quizattemptid];
    if ($options['execute']) {
        $outcome = $regrader->regrade($attemptobj, (int) $item->slot, $actions);
        // A graded question changes the attempt; reload it before grading the next slot.
        if ($outcome->category === result::GRADED) {
            $attempts[$item->quizattemptid] = \mod_quiz\quiz_attempt::create($item->quizattemptid);
        }
    } else {
        $outcome = $regrader->plan($attemptobj->get_question_attempt($item->slot), $actions);
    }
    $counts[$outcome->category] = ($counts[$outcome->category] ?? 0) + 1;
    $mark = $outcome->mark === null ? '' : ' mark=' . format_float($outcome->mark, 2);
    cli_writeln(sprintf(
        "quiz %d attempt %d slot %d (question attempt %d): %s%s - %s",
        $item->quizid,
        $item->quizattemptid,
        $item->slot,
        $qaid,
        get_string('category_' . $outcome->category, 'tool_regradeaitextqs'),
        $mark,
        $outcome->reason
    ));
}

ksort($counts);
cli_writeln('');
foreach ($counts as $category => $count) {
    cli_writeln(get_string('category_' . $category, 'tool_regradeaitextqs') . ": $count");
}
if (!$options['execute']) {
    cli_writeln(get_string('clidryrun', 'tool_regradeaitextqs'));
}
