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
 * Admin page: list quizzes with stuck AI Text questions, preview and regrade them.
 *
 * @package    tool_regradeaitextqs
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/user/lib.php');

use tool_regradeaitextqs\finder;
use tool_regradeaitextqs\regrader;
use tool_regradeaitextqs\result;

$quizid = optional_param('quizid', 0, PARAM_INT);

admin_externalpage_setup('tool_regradeaitextqs', '', $quizid ? ['quizid' => $quizid] : null);
require_capability('tool/regradeaitextqs:regrade', context_system::instance());

$baseurl = new moodle_url('/admin/tool/regradeaitextqs/index.php');
$finder = new finder();
$regrader = new regrader();

if (!$quizid) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('pluginname', 'tool_regradeaitextqs'));
    echo html_writer::tag('p', get_string('intro', 'tool_regradeaitextqs'));
    $quizzes = $finder->summarise();
    if (!$quizzes) {
        echo $OUTPUT->notification(get_string('nonestuck', 'tool_regradeaitextqs'), 'info');
        echo $OUTPUT->footer();
        exit;
    }
    $table = new html_table();
    $table->head = [
        get_string('course'),
        get_string('modulename', 'mod_quiz'),
        get_string('stuckcount', 'tool_regradeaitextqs'),
        '',
    ];
    foreach ($quizzes as $quiz) {
        $table->data[] = [
            html_writer::link(
                new moodle_url('/course/view.php', ['id' => $quiz->courseid]),
                format_string($quiz->coursename, true, ['context' => context_course::instance($quiz->courseid)])
            ),
            format_string($quiz->quizname),
            (int) $quiz->stuck,
            html_writer::link(
                new moodle_url($baseurl, ['quizid' => $quiz->quizid]),
                get_string('review', 'tool_regradeaitextqs')
            ),
        ];
    }
    echo html_writer::table($table);
    echo $OUTPUT->footer();
    exit;
}

$quiz = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);
$quizurl = new moodle_url($baseurl, ['quizid' => $quizid]);
$items = $finder->find(null, $quizid);

// Load each quiz attempt once and assess every stuck question in it.
$attempts = [];
$assessments = [];
foreach ($items as $qaid => $item) {
    if (!isset($attempts[$item->quizattemptid])) {
        $attempts[$item->quizattemptid] = \mod_quiz\quiz_attempt::create($item->quizattemptid);
    }
    $assessments[$qaid] = $regrader->assess($attempts[$item->quizattemptid]->get_question_attempt($item->slot));
}

if (optional_param('regrade', false, PARAM_RAW) !== false) {
    require_sesskey();
    // Only ids that are still stuck in this quiz are acted on, whatever the request names.
    $selected = array_intersect_key($items, array_flip(optional_param_array('qaids', [], PARAM_INT)));
    $graded = 0;
    $queued = [];
    $skipped = 0;
    foreach ($selected as $qaid => $item) {
        $category = $assessments[$qaid]->category;
        if ($category === result::REREAD) {
            $outcome = $regrader->regrade($attempts[$item->quizattemptid], (int) $item->slot, false);
            if ($outcome->category === result::GRADED) {
                $graded++;
            } else {
                $skipped++;
            }
        } else if ($category === result::ASKAI) {
            $queued[] = (int) $qaid;
        } else {
            $skipped++;
        }
    }
    if ($queued) {
        $task = new \tool_regradeaitextqs\task\regrade_with_ai();
        $task->set_custom_data(['questionattemptids' => $queued]);
        $task->set_userid($USER->id);
        \core\task\manager::queue_adhoc_task($task);
    }
    redirect(
        $quizurl,
        get_string(
            'regradedone',
            'tool_regradeaitextqs',
            (object) ['graded' => $graded, 'queued' => count($queued), 'skipped' => $skipped]
        ),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, MUST_EXIST);
echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($quiz->name, true, ['context' => context_module::instance($cm->id)]));
echo html_writer::tag('p', html_writer::link($baseurl, get_string('backtolist', 'tool_regradeaitextqs')));

if (!$items) {
    echo $OUTPUT->notification(get_string('nonestuckquiz', 'tool_regradeaitextqs'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$users = user_get_users_by_id(array_unique(array_column($items, 'userid')));
$table = new html_table();
$table->head = [
    '',
    get_string('student', 'tool_regradeaitextqs'),
    get_string('question'),
    get_string('action', 'tool_regradeaitextqs'),
    get_string('newmark', 'tool_regradeaitextqs'),
    get_string('reason', 'tool_regradeaitextqs'),
];
foreach ($items as $qaid => $item) {
    $assessment = $assessments[$qaid];
    $actionable = in_array($assessment->category, [result::REREAD, result::ASKAI], true);
    $checkbox = $actionable ? html_writer::checkbox(
        'qaids[]',
        $qaid,
        true,
        '',
        ['aria-label' => get_string('selectrow', 'tool_regradeaitextqs')]
    ) : '';
    $qa = $attempts[$item->quizattemptid]->get_question_attempt($item->slot);
    $mark = $assessment->category === result::REREAD
        ? format_float($assessment->mark, 2) . ' / ' . format_float($qa->get_max_mark(), 2) : '';
    $review = new moodle_url('/mod/quiz/review.php', ['attempt' => $item->quizattemptid, 'slot' => $item->slot]);
    $table->data[] = [
        $checkbox,
        html_writer::link($review, s(fullname($users[$item->userid]))),
        format_string($item->questionname),
        get_string('category_' . $assessment->category, 'tool_regradeaitextqs'),
        $mark,
        s($assessment->reason),
    ];
}

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $quizurl->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::table($table);
echo html_writer::tag('p', get_string('regradehelp', 'tool_regradeaitextqs'));
echo html_writer::empty_tag('input', ['type' => 'submit', 'name' => 'regrade', 'class' => 'btn btn-primary',
    'value' => get_string('regradeselected', 'tool_regradeaitextqs')]);
echo html_writer::end_tag('form');
echo $OUTPUT->footer();
