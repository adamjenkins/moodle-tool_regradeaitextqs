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

/**
 * The assessment or outcome for one stuck question attempt.
 *
 * @package    tool_regradeaitextqs
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result {
    /** @var string The stored AI reply can be re-read and gives a mark. */
    const REREAD = 'reread';
    /** @var string The AI must be asked again. */
    const ASKAI = 'askai';
    /** @var string The answer never reached the AI (empty or outside the word limits): it gets a mark of zero. */
    const INVALID = 'invalid';
    /** @var string Nothing is done for this question in this run (its action is set to leave it): a teacher must grade it. */
    const TEACHER = 'teacher';
    /** @var string Regrading was tried and gave no mark, or could not run. */
    const FAILED = 'failed';
    /** @var string The question was graded by the tool. */
    const GRADED = 'graded';
    /** @var string[] The assessments the tool can act on; each has a default-action setting. */
    const ACTIONS = [self::REREAD, self::ASKAI, self::INVALID];

    /** @var string one of the class constants. */
    public readonly string $category;
    /** @var float|null the mark out of the question attempt's max mark, when one was found. */
    public readonly ?float $mark;
    /** @var string the formatted AI feedback to store as the comment, when one was found. */
    public readonly string $feedback;
    /** @var string a plain-text explanation for the teacher/admin. */
    public readonly string $reason;

    /**
     * Create a result.
     *
     * @param string $category one of the class constants.
     * @param float|null $mark the mark out of the question attempt's max mark, when one was found.
     * @param string $feedback the formatted AI feedback to store as the comment, when one was found.
     * @param string $reason a plain-text explanation for the teacher/admin.
     */
    public function __construct(string $category, ?float $mark = null, string $feedback = '', string $reason = '') {
        $this->category = $category;
        $this->mark = $mark;
        $this->feedback = $feedback;
        $this->reason = $reason;
    }
}
