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
use question_attempt;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/questionlib.php');

/**
 * Assesses and regrades stuck AI Text question attempts.
 *
 * The mark calculation follows qtype_aitext_question::grade_response(), so a regraded question
 * gets the mark the AI reply would have given it at the time.
 *
 * @package    tool_regradeaitextqs
 * @copyright  2024 Marcus Green
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class regrader {
    /** @var callable sends a prompt to the AI: fn(\qtype_aitext_question $question, string $prompt): string. */
    protected $airequest;

    /**
     * Create a regrader.
     *
     * @param callable|null $airequest replaces the AI call (for tests); defaults to the question's own perform_request().
     */
    public function __construct(?callable $airequest = null) {
        $this->airequest = $airequest ?? function (\qtype_aitext_question $question, string $prompt): string {
            return $question->perform_request($prompt, 'feedback');
        };
    }

    /**
     * Decide what can be done for a stuck question attempt, without calling the AI.
     *
     * @param question_attempt $qa the stuck question attempt.
     * @return result REREAD (with mark and feedback), ASKAI, TEACHER or FAILED.
     */
    public function assess(question_attempt $qa): result {
        $question = $qa->get_question();
        if (!($question instanceof \qtype_aitext_question)) {
            return new result(result::FAILED, reason: get_string('reason_notaitext', 'tool_regradeaitextqs'));
        }

        // The AI's raw reply is kept in the comment when it could not be parsed at the time.
        $comment = $this->latest_behaviour_var($qa, '_comment');
        if ($comment !== null && trim(strip_tags($comment)) !== '') {
            $parsed = $question->process_feedback($this->reply_from_comment($comment));
            if (is_numeric($parsed->marks)) {
                return new result(
                    result::REREAD,
                    $this->mark_for($qa, (float) $parsed->marks),
                    $parsed->feedback,
                    get_string('reason_reread', 'tool_regradeaitextqs')
                );
            }
        }

        // Answers that never reached the AI (under the word minimum, or empty) stay with the teacher.
        if (!$question->is_complete_response($qa->get_last_qt_data())) {
            return new result(result::TEACHER, reason: get_string('reason_incomplete', 'tool_regradeaitextqs'));
        }

        $reason = $comment === null ? 'reason_askai_noreply' : 'reason_askai_nomarks';
        return new result(result::ASKAI, reason: get_string($reason, 'tool_regradeaitextqs'));
    }

    /**
     * Regrade one stuck question: re-read the stored reply, or ask the AI again, then record the grade.
     *
     * @param quiz_attempt $attemptobj the finished quiz attempt.
     * @param int $slot the slot of the stuck question.
     * @param bool $useai whether the AI may be asked again when the stored reply gives no mark.
     * @return result GRADED (with mark and feedback), ASKAI (when $useai is false), TEACHER or FAILED.
     */
    public function regrade(quiz_attempt $attemptobj, int $slot, bool $useai): result {
        $qa = $attemptobj->get_question_attempt($slot);
        if ((string) $qa->get_state() !== (string) \question_state::$needsgrading) {
            return new result(result::FAILED, reason: get_string('reason_notstuck', 'tool_regradeaitextqs'));
        }

        $assessment = $this->assess($qa);
        if ($assessment->category === result::REREAD) {
            $this->record_grade($attemptobj, $slot, $assessment->mark, $assessment->feedback);
            return new result(result::GRADED, $assessment->mark, $assessment->feedback, $assessment->reason);
        }
        if ($assessment->category !== result::ASKAI || !$useai) {
            return $assessment;
        }

        $question = $qa->get_question();
        // Re-send the prompt stored at grading time; rebuild it only for attempts that never stored one.
        $prompt = $this->latest_behaviour_var($qa, '_aiprompt');
        if ($prompt === null || trim($prompt) === '') {
            $prompt = $question->build_full_ai_prompt(
                $qa->get_last_qt_var('answer', ''),
                $question->aiprompt,
                $question->defaultmark,
                $question->markscheme
            );
        }
        try {
            $reply = ($this->airequest)($question, $prompt);
        } catch (\moodle_exception $e) {
            return new result(result::FAILED, reason: get_string('reason_aifailed', 'tool_regradeaitextqs', $e->getMessage()));
        }
        $parsed = $question->process_feedback($reply);
        if (!is_numeric($parsed->marks)) {
            return new result(
                result::FAILED,
                feedback: $parsed->feedback,
                reason: get_string('reason_ainomarks', 'tool_regradeaitextqs')
            );
        }
        $mark = $this->mark_for($qa, (float) $parsed->marks);
        $this->record_grade($attemptobj, $slot, $mark, $parsed->feedback);
        return new result(result::GRADED, $mark, $parsed->feedback, get_string('reason_askedai', 'tool_regradeaitextqs'));
    }

    /**
     * Turn the AI's marks into a mark out of the question attempt's max mark.
     *
     * Same clamping as qtype_aitext_question::grade_response().
     *
     * @param question_attempt $qa the question attempt.
     * @param float $marks the marks the AI gave, out of the question's default mark.
     * @return float
     */
    protected function mark_for(question_attempt $qa, float $marks): float {
        $defaultmark = (float) $qa->get_question()->defaultmark;
        $fraction = $defaultmark > 0 ? min(max(0.0, $marks / $defaultmark), 1.0) : 0.0;
        return $fraction * $qa->get_max_mark();
    }

    /**
     * Record a grade and comment the way the quiz's manual-grading page does.
     *
     * @param quiz_attempt $attemptobj the finished quiz attempt.
     * @param int $slot the slot to grade.
     * @param float $mark the mark out of the question attempt's max mark.
     * @param string $feedback the formatted comment (HTML).
     */
    protected function record_grade(quiz_attempt $attemptobj, int $slot, float $mark, string $feedback): void {
        global $DB;

        $qa = $attemptobj->get_question_attempt($slot);
        $commentfield = $qa->get_behaviour_field_name('comment');
        $postdata = [
            'slots' => (string) $slot,
            $qa->get_control_field_name('sequencecheck') => $qa->get_sequence_check_count(),
            $commentfield => $feedback,
            // Without a draft item id the question engine drops the comment.
            $commentfield . ':itemid' => file_get_unused_draft_itemid(),
            $qa->get_behaviour_field_name('commentformat') => FORMAT_HTML,
            $qa->get_behaviour_field_name('mark') => (string) round($mark, 7),
            $qa->get_behaviour_field_name('maxmark') => (string) $qa->get_max_mark(),
        ];

        $transaction = $DB->start_delegated_transaction();
        $attemptobj->process_submitted_actions(time(), false, $postdata);
        $transaction->allow_commit();

        \mod_quiz\event\question_manually_graded::create([
            'objectid' => $qa->get_question_id(),
            'courseid' => $attemptobj->get_courseid(),
            'context' => $attemptobj->get_quizobj()->get_context(),
            'other' => [
                'quizid' => $attemptobj->get_quizid(),
                'attemptid' => $attemptobj->get_attemptid(),
                'slot' => $slot,
            ],
        ])->trigger();
    }

    /**
     * Get the latest value of a behaviour variable (stored as "-name") on any step.
     *
     * @param question_attempt $qa the question attempt.
     * @param string $name the variable name without the leading "-".
     * @return string|null
     */
    protected function latest_behaviour_var(question_attempt $qa, string $name): ?string {
        foreach ($qa->get_reverse_step_iterator() as $step) {
            if ($step->has_behaviour_var($name)) {
                return $step->get_behaviour_var($name);
            }
        }
        return null;
    }

    /**
     * Recover the AI's raw reply from a stored comment.
     *
     * qtype_aitext stores the reply through format_text() (Markdown to HTML) and appends the
     * disclaimer, which sits outside the JSON and is ignored by the parser.
     *
     * @param string $comment the stored comment HTML.
     * @return string
     */
    protected function reply_from_comment(string $comment): string {
        return html_entity_decode(strip_tags($comment), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
