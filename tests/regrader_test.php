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
 * Tests for the finder, the regrader and the adhoc task.
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
final class regrader_test extends \advanced_testcase {
    /** @var \stdClass the quiz. */
    protected $quiz;
    /** @var \stdClass the question record. */
    protected $question;
    /** @var \stdClass the student. */
    protected $student;

    /**
     * Create a course with a one-question quiz (an AI Text question worth 1 mark) and a student.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('disclaimer', '(AI)', 'qtype_aitext');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['summary' => '']);
        $this->student = $generator->create_and_enrol($course, 'student');
        $this->quiz = $generator->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $course->id,
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
     * @return quiz_attempt the finished attempt, reloaded.
     */
    protected function finished_attempt(string $answer): quiz_attempt {
        $quizobj = quiz_settings::create($this->quiz->id, $this->student->id);
        $attempt = quiz_prepare_and_start_new_attempt($quizobj, 1, null, false, [], [], $this->student->id);
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

        $outcome = $regrader->regrade($attemptobj, 1, false);
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
        $this->assertEquals(result::ASKAI, (new regrader())->regrade($attemptobj, 1, false)->category);

        $sent = null;
        $regrader = new regrader(function ($question, $prompt) use (&$sent) {
            $sent = $prompt;
            return '{"feedback": "A complete story.", "marks": 1}';
        });
        $outcome = $regrader->regrade($attemptobj, 1, true);
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
        $outcome = $failing->regrade($attemptobj, 1, true);
        $this->assertEquals(result::FAILED, $outcome->category);
        $this->assertStringStartsWith('The AI request failed:', $outcome->reason);

        $nomark = new regrader(fn() => 'Still no JSON here.');
        $outcome = $nomark->regrade(quiz_attempt::create($attemptobj->get_attemptid()), 1, true);
        $this->assertEquals(result::FAILED, $outcome->category);
        $this->assertEquals(get_string('reason_ainomarks', 'tool_regradeaitextqs'), $outcome->reason);

        $qa = quiz_attempt::create($attemptobj->get_attemptid())->get_question_attempt(1);
        $this->assertEquals('needsgrading', (string) $qa->get_state());
    }

    /**
     * An answer under the word minimum never reached the AI and is left for the teacher.
     */
    public function test_answer_under_word_minimum_is_left_for_teacher(): void {
        global $DB;
        $DB->set_field('qtype_aitext', 'minwordlimit', 10, ['questionid' => $this->question->id]);
        $attemptobj = $this->finished_attempt('<p>A frog.</p>');
        $this->assertEquals('needsgrading', (string) $attemptobj->get_question_attempt(1)->get_state());

        $calls = 0;
        $regrader = new regrader(function () use (&$calls) {
            $calls++;
            return '{"feedback": "x", "marks": 1}';
        });
        $this->assertEquals(result::TEACHER, $regrader->assess($attemptobj->get_question_attempt(1))->category);
        $this->assertEquals(result::TEACHER, $regrader->regrade($attemptobj, 1, true)->category);
        $this->assertEquals(0, $calls);
        $qa = quiz_attempt::create($attemptobj->get_attemptid())->get_question_attempt(1);
        $this->assertEquals('needsgrading', (string) $qa->get_state());
    }

    /**
     * A question the tool has graded is no longer listed.
     */
    public function test_finder_ignores_graded_questions(): void {
        $attemptobj = $this->finished_attempt('<p>Once upon a time a frog met a prince.</p>');
        (new regrader(fn() => '{"feedback": "Fine.", "marks": 1}'))->regrade($attemptobj, 1, true);
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
