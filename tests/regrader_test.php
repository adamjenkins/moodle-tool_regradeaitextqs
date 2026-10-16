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

namespace tool_regradeaitextqs;

use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Tests for the finder, the regrader, the default actions and the tasks.
 *
 * Under PHPUnit qtype_aitext's perform_request() returns the plain text "AI Feedback", which has
 * no mark, so a finished attempt is naturally left in "Requires grading".
 *
 * @package    tool_regradeaitextqs
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(finder::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(regrader::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(task\regrade_with_ai::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(task\regrade_stuck::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(defaults::class)]
final class regrader_test extends \advanced_testcase {
    /** @var \stdClass the quiz. */
    protected $quiz;
    /** @var \stdClass the question record. */
    protected $question;
    /** @var \stdClass the student. */
    protected $student;
    /** @var \stdClass the course. */
    protected $course;

    /**
     * Create a course with a one-question quiz (an AI Text question worth 1 mark) and a student.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('disclaimer', '(AI)', 'qtype_aitext');

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course(['summary' => '']);
        $this->student = $generator->create_and_enrol($this->course, 'student');
        $this->quiz = $generator->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $this->course->id,
            'questionsperpage' => 0,
            'grade' => 10.0,
            'sumgrades' => 1.0,
        ]);
        $questiongenerator = $generator->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category();
        $this->question = $questiongenerator->create_question('aitext', 'editor', ['category' => $category->id]);
        quiz_add_quiz_question($this->question->id, $this->quiz, 0, 1.0);
    }

    /**
     * Run a student attempt answering the question, and finish it.
     *
     * @param string $answer the student's answer.
     * @param \stdClass|null $student the student (default: the one from setUp()).
     * @return quiz_attempt the finished attempt, reloaded.
     */
    protected function finished_attempt(string $answer, ?\stdClass $student = null): quiz_attempt {
        $student = $student ?? $this->student;
        $quizobj = quiz_settings::create($this->quiz->id, $student->id);
        $attempt = quiz_prepare_and_start_new_attempt($quizobj, 1, null, false, [], [], $student->id);
        $attemptobj = quiz_attempt::create($attempt->id);
        $attemptobj->process_submitted_actions(time(), false, [1 => ['answer' => $answer, 'answerformat' => FORMAT_HTML]]);
        $attemptobj = quiz_attempt::create($attempt->id);
        if (method_exists($attemptobj, 'process_grade_submission')) {
            $attemptobj->process_submit(time(), false);
            $attemptobj->process_grade_submission(time());
        } else {
            $attemptobj->process_finish(time(), false);
        }
        return quiz_attempt::create($attempt->id);
    }

    /**
     * Replace the stored AI comment of slot 1, as if the AI had returned this raw reply.
     *
     * @param quiz_attempt $attemptobj the attempt.
     * @param string $comment the comment HTML to store.
     */
    protected function set_stored_comment(quiz_attempt $attemptobj, string $comment): void {
        global $DB;
        $qaid = $attemptobj->get_question_attempt(1)->get_database_id();
        $dataid = $DB->get_field_sql(
            "SELECT d.id
               FROM {question_attempt_step_data} d
               JOIN {question_attempt_steps} s ON s.id = d.attemptstepid
              WHERE s.questionattemptid = :qaid AND d.name = :name",
            ['qaid' => $qaid, 'name' => '-_comment'],
            MUST_EXIST
        );
        $DB->set_field('question_attempt_step_data', 'value', $comment, ['id' => $dataid]);
    }

    /**
     * A finished attempt whose AI reply had no mark is found, and assessed as needing the AI again.
     */
    public function test_stuck_attempt_is_found_and_needs_ai(): void {
        $attemptobj = $this->finished_attempt('<p>Once upon a time a frog met a prince.</p>');
        $this->assertEquals('needsgrading', (string) $attemptobj->get_question_attempt(1)->get_state());

        $items = (new finder())->find(null, $this->quiz->id);
        $this->assertCount(1, $items);
        $item = reset($items);
        $this->assertEquals($attemptobj->get_attemptid(), $item->quizattemptid);
        $summary = (new finder())->summarise();
        $this->assertEquals(1, $summary[$this->quiz->id]->stuck);

        $assessment = (new regrader())->assess($attemptobj->get_question_attempt(1));
        $this->assertEquals(result::ASKAI, $assessment->category);
        $this->assertEquals(get_string('reason_askai_nomarks', 'tool_regradeaitextqs'), $assessment->reason);
    }

    /**
     * A stored malformed-JSON reply (the reported case) is re-read and graded like a manual grade.
     */
    public function test_reread_grades_malformed_json_comment(): void {
        global $DB;
        $attemptobj = $this->finished_attempt('<p>Once upon a time a frog met a prince.</p>');
        // The comment exactly as qtype_aitext stored an unquoted-key reply before the parser fix.
        $this->set_stored_comment(
            $attemptobj,
            '<p>{feedback:"[[A frog met a prince]] is a clear opening.","marks":0.5}</p> (AI)'
        );
        $attemptobj = quiz_attempt::create($attemptobj->get_attemptid());

        $calls = 0;
        $regrader = new regrader(function () use (&$calls) {
            $calls++;
            return '';
        });
        $assessment = $regrader->assess($attemptobj->get_question_attempt(1));
        $this->assertEquals(result::REREAD, $assessment->category);
        $this->assertEquals(0.5, $assessment->mark);

        $outcome = $regrader->regrade($attemptobj, 1, [result::REREAD]);
        $this->assertEquals(result::GRADED, $outcome->category);
        $this->assertEquals(0, $calls, 'Re-reading must not call the AI.');

        $attemptobj = quiz_attempt::create($attemptobj->get_attemptid());
        $qa = $attemptobj->get_question_attempt(1);
        $this->assertEquals('mangrpartial', (string) $qa->get_state());
        $this->assertEquals(0.5, $qa->get_mark());
        [$comment] = $qa->get_manual_comment();
        $this->assertStringContainsString('"A frog met a prince" is a clear opening.', $comment);
        $this->assertStringNotContainsString('{feedback', $comment);
        $this->assertEquals(0.5, (float) $DB->get_field('quiz_attempts', 'sumgrades', ['id' => $attemptobj->get_attemptid()]));
        // Quiz grade is out of 10, the attempt scored 0.5 of 1.
        $this->assertEquals(5.0, (float) $DB->get_field(
            'quiz_grades',
            'grade',
            ['quiz' => $this->quiz->id, 'userid' => $this->student->id]
        ));
        $this->assertCount(0, (new finder())->find(null, $this->quiz->id));
    }

    /**
     * Without a usable stored reply, the stored prompt is sent to the AI again and the new reply graded.
     */
    public function test_ask_ai_again_uses_stored_prompt(): void {
        $attemptobj = $this->finished_attempt('<p>Once upon a time a frog met a prince.</p>');
        $storedprompt = null;
        foreach ($attemptobj->get_question_attempt(1)->get_step_iterator() as $step) {
            if ($step->has_behaviour_var('_aiprompt')) {
                $storedprompt = $step->get_behaviour_var('_aiprompt');
            }
        }
        $this->assertNotEmpty($storedprompt);

        // Without permission to use the AI nothing changes.
        $this->assertEquals(
            result::TEACHER,
            (new regrader())->regrade($attemptobj, 1, [result::REREAD, result::INVALID])->category
        );

        $sent = null;
        $regrader = new regrader(function ($question, $prompt) use (&$sent) {
            $sent = $prompt;
            return '{"feedback": "A complete story.", "marks": 1}';
        });
        $outcome = $regrader->regrade($attemptobj, 1, result::ACTIONS);
        $this->assertEquals(result::GRADED, $outcome->category);
        $this->assertEquals($storedprompt, $sent);

        $qa = quiz_attempt::create($attemptobj->get_attemptid())->get_question_attempt(1);
        $this->assertEquals('mangrright', (string) $qa->get_state());
        $this->assertEquals(1.0, $qa->get_mark());
    }

    /**
     * An AI failure or a reply without a mark leaves the question untouched.
     */
    public function test_ai_failure_leaves_question_stuck(): void {
        $attemptobj = $this->finished_attempt('<p>Once upon a time a frog met a prince.</p>');

        $failing = new regrader(function () {
            throw new \moodle_exception('err_retrievingfeedback', 'qtype_aitext');
        });
        $outcome = $failing->regrade($attemptobj, 1, result::ACTIONS);
        $this->assertEquals(result::FAILED, $outcome->category);
        $this->assertStringStartsWith('The AI request failed:', $outcome->reason);

        $nomark = new regrader(fn() => 'Still no JSON here.');
        $outcome = $nomark->regrade(quiz_attempt::create($attemptobj->get_attemptid()), 1, result::ACTIONS);
        $this->assertEquals(result::FAILED, $outcome->category);
        $this->assertEquals(get_string('reason_ainomarks', 'tool_regradeaitextqs'), $outcome->reason);

        $qa = quiz_attempt::create($attemptobj->get_attemptid())->get_question_attempt(1);
        $this->assertEquals('needsgrading', (string) $qa->get_state());
    }

    /**
     * An answer under the word minimum never reached the AI: it gets zero with the invalid-answer feedback.
     */
    public function test_answer_under_word_minimum_is_graded_zero(): void {
        global $DB;
        $DB->set_field('qtype_aitext', 'minwordlimit', 10, ['questionid' => $this->question->id]);
        set_config('invalidfeedback', "Too short & invalid.\nTry again.", 'tool_regradeaitextqs');
        $attemptobj = $this->finished_attempt('<p>A frog.</p>');
        $this->assertEquals('needsgrading', (string) $attemptobj->get_question_attempt(1)->get_state());

        $calls = 0;
        $regrader = new regrader(function () use (&$calls) {
            $calls++;
            return '{"feedback": "x", "marks": 1}';
        });
        $assessment = $regrader->assess($attemptobj->get_question_attempt(1));
        $this->assertEquals(result::INVALID, $assessment->category);
        $this->assertSame(0.0, $assessment->mark);

        // When its action is not allowed it is left for the teacher.
        $this->assertEquals(result::TEACHER, $regrader->regrade($attemptobj, 1, [result::REREAD, result::ASKAI])->category);
        $qa = quiz_attempt::create($attemptobj->get_attemptid())->get_question_attempt(1);
        $this->assertEquals('needsgrading', (string) $qa->get_state());

        $outcome = $regrader->regrade(quiz_attempt::create($attemptobj->get_attemptid()), 1, result::ACTIONS);
        $this->assertEquals(result::GRADED, $outcome->category);
        $this->assertEquals(0, $calls, 'An invalid answer must not be sent to the AI.');
        $qa = quiz_attempt::create($attemptobj->get_attemptid())->get_question_attempt(1);
        $this->assertEquals('mangrwrong', (string) $qa->get_state());
        $this->assertSame(0.0, (float) $qa->get_mark());
        [$comment] = $qa->get_manual_comment();
        $this->assertEquals('<p>Too short &amp; invalid.<br />' . "\n" . 'Try again.</p>', $comment);
        $this->assertCount(0, (new finder())->find(null, $this->quiz->id));
    }

    /**
     * An answer with no text (qtype_aitext counts it as complete) is invalid too, not sent to the AI again.
     */
    public function test_blank_answer_is_invalid(): void {
        $attemptobj = $this->finished_attempt('<p>&nbsp; </p>');
        $this->assertEquals('needsgrading', (string) $attemptobj->get_question_attempt(1)->get_state());
        $calls = 0;
        $regrader = new regrader(function () use (&$calls) {
            $calls++;
            return '{"feedback": "x", "marks": 1}';
        });
        $this->assertEquals(result::INVALID, $regrader->assess($attemptobj->get_question_attempt(1))->category);
        $this->assertEquals(result::GRADED, $regrader->regrade($attemptobj, 1, result::ACTIONS)->category);
        $this->assertEquals(0, $calls);
        $qa = quiz_attempt::create($attemptobj->get_attemptid())->get_question_attempt(1);
        $this->assertEquals('mangrwrong', (string) $qa->get_state());
    }

    /**
     * With no invalid-answer feedback set, the default text is the comment.
     */
    public function test_invalid_feedback_falls_back_to_default(): void {
        set_config('invalidfeedback', '  ', 'tool_regradeaitextqs');
        $this->assertEquals(
            '<p>' . s(get_string('invalidfeedback_default', 'tool_regradeaitextqs')) . '</p>',
            defaults::invalid_feedback()
        );
    }

    /**
     * The adhoc task re-checks the capability and the quiz attempt before grading.
     */
    public function test_task_rechecks_capability_and_attempt(): void {
        global $DB;
        $attemptobj = $this->finished_attempt('<p>Once upon a time a frog met a prince.</p>');
        $this->set_stored_comment($attemptobj, '<p>{feedback:"Good.","marks":1}</p> (AI)');
        $qaid = $attemptobj->get_question_attempt(1)->get_database_id();
        $task = new task\regrade_with_ai();
        $task->set_custom_data(['questionattemptids' => [$qaid]]);
        $state = fn() => (string) quiz_attempt::create($attemptobj->get_attemptid())->get_question_attempt(1)->get_state();

        // A user without the capability (for example, one whose role was removed after queueing) grades nothing.
        $this->setUser($this->student);
        ob_start();
        $task->execute();
        $this->assertStringContainsString('may not regrade stuck AI Text questions', ob_get_clean());
        $this->assertEquals('needsgrading', $state());

        // A preview attempt is skipped even when its id is queued.
        $this->setAdminUser();
        $DB->set_field('quiz_attempts', 'preview', 1, ['id' => $attemptobj->get_attemptid()]);
        ob_start();
        $task->execute();
        $this->assertStringContainsString("Question attempt $qaid is not in a finished, non-preview quiz attempt", ob_get_clean());
        $this->assertEquals('needsgrading', $state());
    }

    /**
     * The scheduled task grades re-reads and invalid answers, and queues each AI regrade once.
     */
    public function test_scheduled_task_applies_default_actions(): void {
        global $DB;
        $DB->set_field('qtype_aitext', 'minwordlimit', 10, ['questionid' => $this->question->id]);
        $story = '<p>Once upon a time a frog met a prince in a dark green forest by the river.</p>';
        $generator = $this->getDataGenerator();
        $askai = $this->finished_attempt($story);
        $reread = $this->finished_attempt($story, $generator->create_and_enrol($this->course, 'student'));
        $this->set_stored_comment($reread, '<p>{feedback:"Good.","marks":1}</p> (AI)');
        $invalid = $this->finished_attempt('<p>A frog.</p>', $generator->create_and_enrol($this->course, 'student'));
        $askaiqaid = $askai->get_question_attempt(1)->get_database_id();

        // Leaving invalid answers for the teacher is respected.
        set_config('action_invalid', defaults::LEAVE, 'tool_regradeaitextqs');
        $this->assertEquals([result::REREAD, result::ASKAI], defaults::actions());
        $task = new task\regrade_stuck();
        ob_start();
        $task->execute();
        $output = ob_get_clean();
        $this->assertStringContainsString("Question attempt $askaiqaid: queued.", $output);

        $state = fn(quiz_attempt $a) => (string) quiz_attempt::create($a->get_attemptid())->get_question_attempt(1)->get_state();
        $this->assertEquals('needsgrading', $state($askai));
        $this->assertEquals('mangrright', $state($reread));
        $this->assertEquals('needsgrading', $state($invalid));
        $queued = \core\task\manager::get_adhoc_tasks('\\tool_regradeaitextqs\\task\\regrade_with_ai');
        $this->assertCount(1, $queued);
        $this->assertEquals([$askaiqaid], reset($queued)->get_custom_data()->questionattemptids);

        // A second run grades the invalid answer and does not queue the waiting AI regrade again.
        set_config('action_invalid', defaults::ACT, 'tool_regradeaitextqs');
        ob_start();
        $task->execute();
        $output = ob_get_clean();
        $this->assertStringContainsString("Question attempt $askaiqaid: already queued.", $output);
        $this->assertEquals('mangrwrong', $state($invalid));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks('\\tool_regradeaitextqs\\task\\regrade_with_ai'));

        // With every action set to leave, nothing is touched.
        foreach (result::ACTIONS as $category) {
            set_config('action_' . $category, defaults::LEAVE, 'tool_regradeaitextqs');
        }
        ob_start();
        $task->execute();
        $this->assertStringContainsString('nothing to do', ob_get_clean());
    }

    /**
     * A question the tool has graded is no longer listed.
     */
    public function test_finder_ignores_graded_questions(): void {
        $attemptobj = $this->finished_attempt('<p>Once upon a time a frog met a prince.</p>');
        (new regrader(fn() => '{"feedback": "Fine.", "marks": 1}'))->regrade($attemptobj, 1, result::ACTIONS);
        $this->assertCount(0, (new finder())->find());
        $this->assertCount(0, (new finder())->summarise());
    }

    /**
     * The adhoc task regrades each queued question that is still stuck, and skips missing ones.
     */
    public function test_task_reports_each_queued_question(): void {
        $attemptobj = $this->finished_attempt('<p>Once upon a time a frog met a prince.</p>');
        $this->set_stored_comment($attemptobj, '<p>{feedback:"Good.","marks":1}</p> (AI)');
        $qaid = $attemptobj->get_question_attempt(1)->get_database_id();

        $task = new task\regrade_with_ai();
        $task->set_custom_data(['questionattemptids' => [$qaid, 999999]]);
        ob_start();
        $task->execute();
        $output = ob_get_clean();

        $this->assertStringContainsString("Question attempt $qaid: graded mark 1.00.", $output);
        $this->assertStringContainsString('Question attempt 999999 no longer exists.', $output);
        $qa = quiz_attempt::create($attemptobj->get_attemptid())->get_question_attempt(1);
        $this->assertEquals('mangrright', (string) $qa->get_state());
    }
}
