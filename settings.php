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
 * Admin tree entries and default-action settings for tool_regradeaitextqs.
 *
 * @package    tool_regradeaitextqs
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use tool_regradeaitextqs\defaults;
use tool_regradeaitextqs\result;

$ADMIN->add('tools', new admin_category(
    'tool_regradeaitextqs_category',
    new lang_string('pluginname', 'tool_regradeaitextqs')
));

// Registered outside $hassiteconfig so holders of the plugin's own capability can reach the page.
$ADMIN->add('tool_regradeaitextqs_category', new admin_externalpage(
    'tool_regradeaitextqs',
    new lang_string('stuckquestions', 'tool_regradeaitextqs'),
    new moodle_url('/admin/tool/regradeaitextqs/index.php'),
    'tool/regradeaitextqs:regrade'
));

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'tool_regradeaitextqs_settings',
        new lang_string('defaultactions', 'tool_regradeaitextqs')
    );
    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_heading(
            'tool_regradeaitextqs/defaultactionsheading',
            '',
            new lang_string('defaultactions_desc', 'tool_regradeaitextqs', (object) [
                'task' => get_string('task_regradestuck', 'tool_regradeaitextqs'),
                'url' => (new moodle_url('/admin/tool/task/scheduledtasks.php'))->out(),
            ])
        ));
        foreach (result::ACTIONS as $category) {
            $settings->add(new admin_setting_configselect(
                'tool_regradeaitextqs/action_' . $category,
                new lang_string('action_' . $category, 'tool_regradeaitextqs'),
                new lang_string('action_' . $category . '_desc', 'tool_regradeaitextqs'),
                defaults::ACT,
                [
                    defaults::ACT => new lang_string('action_' . $category . '_act', 'tool_regradeaitextqs'),
                    defaults::LEAVE => new lang_string('category_teacher', 'tool_regradeaitextqs'),
                ]
            ));
        }
        $settings->add(new admin_setting_configtextarea(
            'tool_regradeaitextqs/invalidfeedback',
            new lang_string('invalidfeedback', 'tool_regradeaitextqs'),
            new lang_string('invalidfeedback_desc', 'tool_regradeaitextqs'),
            get_string('invalidfeedback_default', 'tool_regradeaitextqs')
        ));
    }
    $ADMIN->add('tool_regradeaitextqs_category', $settings);
}
