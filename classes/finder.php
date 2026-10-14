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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/engine/lib.php');

/**
 * Finds AI Text questions left in "Requires grading" in finished quiz attempts.
 *
 * @package    tool_regradeaitextqs
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class finder {
    /**
     * Shared FROM/WHERE: aitext question attempts whose latest step is needsgrading, in finished,
     * non-preview quiz attempts.
     *
     * @param int|null $courseid limit to one course.
     * @param int|null $quizid limit to one quiz.
     * @return array [sql, params]
     */
    protected function base_sql(?int $courseid, ?int $quizid): array {
        $sql = "FROM {question_attempts} qa
                JOIN {question} q ON q.id = qa.questionid
                JOIN {quiz_attempts} quiza ON quiza.uniqueid = qa.questionusageid
                JOIN {quiz} quiz ON quiz.id = quiza.quiz
                JOIN {course} c ON c.id = quiz.course
                JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
               WHERE q.qtype = :qtype
                 AND quiza.state = :finished
                 AND quiza.preview = 0
                 AND qas.state = :needsgrading
                 AND qas.sequencenumber = (SELECT MAX(s2.sequencenumber)
                                             FROM {question_attempt_steps} s2
                                            WHERE s2.questionattemptid = qa.id)";
        $params = [
            'qtype' => 'aitext',
            'finished' => \mod_quiz\quiz_attempt::FINISHED,
            'needsgrading' => (string) \question_state::$needsgrading,
        ];
        if ($courseid) {
            $sql .= " AND quiz.course = :courseid";
            $params['courseid'] = $courseid;
        }
        if ($quizid) {
            $sql .= " AND quiz.id = :quizid";
            $params['quizid'] = $quizid;
        }
        return [$sql, $params];
    }

    /**
     * List the stuck question attempts.
     *
     * @param int|null $courseid limit to one course.
     * @param int|null $quizid limit to one quiz.
     * @return \stdClass[] keyed by question attempt id, with questionattemptid, slot, quizattemptid,
     *     userid, quizid, quizname, courseid, questionname.
     */
    public function find(?int $courseid = null, ?int $quizid = null): array {
        global $DB;
        [$from, $params] = $this->base_sql($courseid, $quizid);
        $sql = "SELECT qa.id AS questionattemptid, qa.slot, quiza.id AS quizattemptid, quiza.userid,
                       quiz.id AS quizid, quiz.name AS quizname, quiz.course AS courseid, q.name AS questionname
                $from
              ORDER BY quiz.course, quiz.id, quiza.id, qa.slot";
        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Count the stuck question attempts per quiz.
     *
     * @param int|null $courseid limit to one course.
     * @return \stdClass[] keyed by quiz id, with quizid, quizname, courseid, coursename, stuck.
     */
    public function summarise(?int $courseid = null): array {
        global $DB;
        [$from, $params] = $this->base_sql($courseid, null);
        $sql = "SELECT quiz.id AS quizid, quiz.name AS quizname, quiz.course AS courseid,
                       c.fullname AS coursename, COUNT(qa.id) AS stuck
                $from
              GROUP BY quiz.id, quiz.name, quiz.course, c.fullname
              ORDER BY c.fullname, quiz.name";
        return $DB->get_records_sql($sql, $params);
    }
}
