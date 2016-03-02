<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.


/**
 * Heatmap block definition
 *
 * @package    contrib
 * @subpackage block_heatmap
 * @copyright  2016 Michael de Raadt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(__FILE__) . '/../../config.php');

/**
 * Heatmap block class
 *
 * @copyright 2016 Michael de Raadt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_heatmap extends block_base {

    /**
     * Sets the block title.
     *
     * @return none
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_heatmap');
    }

    /**
     * Defines where the block can be added.
     *
     * @return array
     */
    public function applicable_formats() {
        return array(
            'course-view' => true,
        );
    }

    /**
     * Controls global configurability of block.
     *
     * @return bool
     */
    public function instance_allow_config() {
        return false;
    }

    /**
     * Controls global configurability of block.
     *
     * @return bool
     */
    public function has_config() {
        return false;
    }

    /**
     * Controls if a block header is shown based on instance configuration.
     *
     * @return bool
     */
    public function hide_header() {
        return false;
    }

    /**
     * Controls whether multiple block instances are allowed.
     *
     * @return bool
     */
    public function instance_allow_multiple() {
        return false;
    }

    /**
     * Creates the block's main content
     *
     * @return string
     */
    public function get_content() {

        global $COURSE, $DB;

        if (isset($this->content)) {
            return $this->content;
        }

        $this->content = new stdClass;
        $this->content->text = '';
        $this->content->footer = '';

        // Check to see user can view/use the heatmap.
        $context = context_course::instance($COURSE->id);
        if (!isloggedin() || isguestuser() || !has_capability('block/heatmap:view', $context)) {
            $this->content->text = '';
            return $this->content;
        }

        // Get cached logs to avoid hitting the logs each reload.
        $cachedlogs = cache::make('block_heatmap', 'cachedlogs');
        $views = $cachedlogs->get('views'.$COURSE->id);
        $lastcached = $cachedlogs->get('time'.$COURSE->id);
        $now = time();

        // Check cached values are available and from last 5min.
        if (empty($views) || !isset($lastcached) || $lastcached < $now - 300) {

            $uselegacyreader = false; // Flag to determine if we should use the legacy reader.
            $useinternalreader = false; // Flag to determine if we should use the internal reader.
            $minloginternalreader = 0; // Set this to 0 for now.

            // Get list of readers.
            $logmanager = get_log_manager();
            $readers = $logmanager->get_readers();

            // Get preferred reader.
            if (!empty($readers)) {
                foreach ($readers as $readerpluginname => $reader) {
                    // If legacy reader is preferred reader.
                    if ($readerpluginname == 'logstore_legacy') {
                        $uselegacyreader = true;
                    }

                    // If sql_internal_table_reader is preferred reader.
                    if ($reader instanceof \core\log\sql_internal_table_reader) {
                        $useinternalreader = true;
                        $logtable = $reader->get_internal_log_table_name();
                        $minloginternalreader = $DB->get_field_sql('SELECT min(timecreated) FROM {' . $logtable . '}');
                    }
                }
            }

            // If no legacy and no internal log then don't proceed.
            if (!$uselegacyreader && !$useinternalreader) {
                $this->content->text = get_string('nologreaderenabled', 'block_heatmap');
                return $this->content;
            }

            // We want to display the time we are beginning to get logs from in the heading.
            // If we are using the legacy reader check the minimum time in that log table.
            if ($uselegacyreader) {
                $minlog = $DB->get_field_sql('SELECT min(time) FROM {log}');
            }

            // If we are using the internal reader check the minimum time in that table.
            if ($useinternalreader) {
                // If new log table has older data then don't use the minimum time obtained from the legacy table.
                if (empty($minlog) || ($minloginternalreader <= $minlog)) {
                    $uselegacyreader = false;
                }
            }

            // If using legacy log then get activity usage from old table.
            if ($uselegacyreader) {
                // If we are going to use the internal (not legacy) log table, we should only get records
                // from the legacy table that exist before we started adding logs to the new table.
                $limittime = '';
                if (!empty($minloginternalreader)) {
                    $limittime = ' AND time < :timeto ';
                }
                $logactionlike = $DB->sql_like('l.action', ':action');
                $sql = "SELECT cm.id, COUNT('x') AS numviews, COUNT(DISTINCT userid) AS distinctusers
                          FROM {course_modules} cm
                          JOIN {modules} m
                            ON m.id = cm.module
                          JOIN {log} l
                            ON l.cmid = cm.id
                         WHERE cm.course = :courseid
                           AND $logactionlike
                           AND m.visible = :visible $limittime
                           AND l.time > :lastcached
                      GROUP BY cm.id";
                $params = array('courseid' => $COURSE->id, 'action' => 'view%', 'visible' => 1, 'lastcached' => $lastcached);
                if (!empty($minloginternalreader)) {
                    $params['timeto'] = $minloginternalreader;
                }
                $rs = $DB->get_recordset_sql($sql, $params);
                $views = array();
                foreach ($rs as $key => $record) {
                    $views[$key] = $record;
                }
            }

            // Get record from sql_internal_table_reader and merge with records obtained from legacy log (if needed).
            if ($useinternalreader) {
                $sql = "SELECT contextinstanceid as cmid, COUNT('x') AS numviews, COUNT(DISTINCT userid) AS distinctusers
                          FROM {" . $logtable . "} l
                         WHERE courseid = :courseid
                           AND anonymous = 0
                           AND crud = 'r'
                           AND contextlevel = :contextmodule
                           AND timecreated > :lastcached
                      GROUP BY contextinstanceid";
                $params = array('courseid' => $COURSE->id, 'contextmodule' => CONTEXT_MODULE, 'lastcached' => $lastcached);
                $rs = $DB->get_recordset_sql($sql, $params);
                $v = array();
                foreach ($rs as $key => $record) {
                    $v[$key] = $record;
                }
                if (empty($views)) {
                    $views = $v;
                } else {
                    // Merge two view arrays.
                    foreach ($v as $key => $value) {
                        if (isset($views[$key]) && !empty($views[$key]->numviews)) {
                            $views[$key]->numviews += $value->numviews;
                        } else {
                            $views[$key] = $value;
                        }
                    }
                }
            }

            // Cache queried values for next 5min.
            $cachedlogs->set('views'.$COURSE->id, $views);
            $cachedlogs->set('time'.$COURSE->id, $now);
        }

        // Get the min, max and totals.
        if (!empty($views)) {
            $firstactivity = array_shift($views);
            $totalviews = $firstactivity->numviews;
            $totalusers = $firstactivity->distinctusers;
            $minviews = $firstactivity->numviews;
            $maxviews = $firstactivity->numviews;
            foreach ($views as $key => $activity) {
                $totalviews += $activity->numviews;
                if ($activity->numviews < $minviews) {
                    $minviews = $activity->numviews;
                }
                if ($activity->numviews > $maxviews) {
                    $maxviews = $activity->numviews;
                }
                $totalusers += $activity->distinctusers;
            }
            array_unshift($views, $firstactivity);
            $this->content->text .= get_string('totalviews', 'block_heatmap', $totalviews).'<br />';
            $this->content->text .= get_string('distinctuserviews', 'block_heatmap', $totalusers).'<br />';
            $this->content->text .= html_writer::link(
                '#null',
                get_string('toggleheatmap', 'block_heatmap'),
                array('onclick' => 'M.block_heatmap.toggleHeatmap();')
            );
        }

        // Set up JS for injecting heatmap.
        $jsmodule = array(
            'name'     => 'block_heatmap',
            'fullpath' => '/blocks/heatmap/module.js',
            'requires' => array(),
            'strings'  => array(
                array('views', 'block_heatmap'),
                array('distinctusers', 'block_heatmap'),
            ),
        );
        user_preference_allow_ajax_update('heatmaptogglestate', PARAM_BOOL);
        $toggledon = get_user_preferences('heatmaptogglestate', true);
        $arguments = array(
            json_encode($views),
            $minviews,
            $maxviews,
            $toggledon,
        );
        $this->page->requires->js_init_call('M.block_heatmap.initHeatmap', $arguments, false, $jsmodule);

        return $this->content;
    }
}
