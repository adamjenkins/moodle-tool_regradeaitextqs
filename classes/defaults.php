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
 * The default actions and the invalid-answer feedback, from the plugin settings.
 *
 * @package    tool_regradeaitextqs
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class defaults {
    /** @var string Setting value: do the category's action. */
    const ACT = 'act';
    /** @var string Setting value: leave the question for the teacher. */
    const LEAVE = 'leave';

    /**
     * The assessments whose default action is to act on them (an unset setting means act).
     *
     * @return string[] a subset of result::ACTIONS.
     */
    public static function actions(): array {
        return array_values(array_filter(
            result::ACTIONS,
            fn(string $category) => get_config('tool_regradeaitextqs', 'action_' . $category) !== self::LEAVE
        ));
    }

    /**
     * The comment stored with the zero mark for an answer that never reached the AI.
     *
     * @return string HTML.
     */
    public static function invalid_feedback(): string {
        $text = trim((string) get_config('tool_regradeaitextqs', 'invalidfeedback'));
        if ($text === '') {
            $text = get_string('invalidfeedback_default', 'tool_regradeaitextqs');
        }
        return \html_writer::tag('p', nl2br(s($text)));
    }
}
