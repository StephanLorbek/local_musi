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
 * This file contains the definition for the renderable classes for the booking instance
 *
 * @package   local_musi
 * @copyright 2021 Georg Maißer {@link http://www.wunderbyte.at}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_musi\output;

use context_system;
use format_site;
use local_musi\table\musi_table;
use mod_booking\singleton_service;
use moodle_exception;
use moodle_url;
use renderer_base;
use renderable;
use stdClass;
use templatable;
use user_picture;

/**
 * This class prepares data for displaying the teacher page.
 *
 * @package local_musi
 * @copyright 2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author Georg Maißer, Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page_teacher implements renderable, templatable {

    /** @var stdClass $teacher */
    public $teacher = null;

    /** @var bool $error */
    public $error = null;

    /** @var string $errormessage */
    public $errormessage = null;

    /**
     * In the Constructor, we gather all the data we need ans store it in the data property.
     */
    public function __construct(int $teacherid) {
        global $DB;
        // We get the user object of the provided teacher.
        if (!$this->teacher = $DB->get_record('user', ['id' => $teacherid])) {
            $this->error = true;
            $this->errormessage = get_string('teachernotfound', 'local_musi');
            return;
        }

        // Now get a list of all teachers...
        // Now get all teachers that we're interested in.
        $sqlteachers = "SELECT DISTINCT userid FROM {booking_teachers}";
        if ($teacherrecords = $DB->get_records_sql($sqlteachers)) {
            foreach ($teacherrecords as $teacherrecord) {
                $teacherids[] = $teacherrecord->userid;
            }
        }
        // ... and check if the selected teacherid is part of it.
        if (!in_array($teacherid, $teacherids)) {
            $this->error = true;
            $this->errormessage = get_string('notateacher', 'local_musi');
            return;
        }
    }

    /**
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {
        global $PAGE, $CFG;

        if (!isset($PAGE->context)) {
            $PAGE->set_context(context_system::instance());
        }

        $returnarray = [];

        // If we had an error, we return the error message.
        if ($this->error) {
            $returnarray['error'] = $this->error;
            $returnarray['errormessage'] = $this->errormessage;
            return $returnarray;
        }

        // Here we can load custom userprofile fields and add the to the array to render.
        // Right now, we just use a few standard pieces of information.

        // Get all booking options where the teacher is teaching and sort them by instance.
        $teacheroptiontables = $this->get_option_tables_for_teacher($this->teacher->id);

        $returnarray['teacher'] = [
            'teacherid' => $this->teacher->id,
            'firstname' => $this->teacher->firstname,
            'lastname' => $this->teacher->lastname,
            'description' => format_text($this->teacher->description, $this->teacher->descriptionformat),
            'optiontables' => $teacheroptiontables
        ];

        if ($this->teacher->picture) {
            $picture = new \user_picture($this->teacher);
            $picture->size = 150;
            $imageurl = $picture->get_url($PAGE);
            $returnarray['image'] = $imageurl;
        }

        if (self::teacher_messaging_is_possible($this->teacher->id)) {
            $returnarray['messagingispossible'] = true;
        }

        // Add a link to the report of performed teaching units.
        // But only, if the user has the appropriate capability.
        if ((has_capability('mod/booking:updatebooking', $PAGE->context))) {
            $url = new moodle_url('/mod/booking/teacher_performed_units_report.php', ['teacherid' => $this->teacher->id]);
            $returnarray['linktoperformedunitsreport'] = $url->out();
        }
        // Include wwwroot for links.
        $returnarray['wwwroot'] = $CFG->wwwroot;
        return $returnarray;
    }

    /**
     * Helper function to create wunderbyte_tables for all options of a specific teacher.
     *
     * @param int userid of a specific teacher
     * @return array an array of tables as string
     */
    private function get_option_tables_for_teacher(int $teacherid, $perpage = 1000) {

        global $DB;

        $teacheroptiontables = [];

        $bookingidrecords = $DB->get_records_sql(
            "SELECT DISTINCT bookingid FROM {booking_teachers} WHERE userid = :teacherid",
            ['teacherid' => $teacherid]
        );

        $firsttable = true;
        foreach ($bookingidrecords as $bookingidrecord) {

            $bookingid = $bookingidrecord->bookingid;

            if ($booking = singleton_service::get_instance_of_booking_by_bookingid($bookingid)) {

                // We load only the first table directly, the other ones lazy.

                $lazy = $firsttable ? '' : ' lazy="1" ';

                $out = format_text('[allekursekarten id="' . $booking->cmid . '" teacherid="'
                    . $teacherid . '" ' . $lazy . ']', FORMAT_HTML);

                $class = $firsttable ? 'active show' : '';
                $firsttable = false;

                $tablename = preg_replace("/[^a-z]/", '', $booking->settings->name);

                $teacheroptiontables[] = [
                    'bookingid' => $bookingid,
                    'bookinginstancename' => $booking->settings->name,
                    'tablename' => $tablename,
                    'table' => $out,
                    'class' => $class
                ];
            }
        }

        return $teacheroptiontables;
    }

    /**
     * Helper functions which checks if the teacher
     * and current user are at least in one common course,
     * which is a prerequisite for messaging to work with Moodle.
     * If yes, it returns true, else it returns false.
     *
     * @param int $teacherid id of the teacher to check
     * @return bool true if possible, else false
     */
    public static function teacher_messaging_is_possible(int $teacherid) {
        global $DB, $USER;

        // SQL to check if teacher has common courses with the logged in $USER.
        $sql = "SELECT e.courseid
                FROM {user_enrolments} ue
                LEFT JOIN {enrol} e
                ON e.id = ue.enrolid
                WHERE ue.status = 0
                AND userid = :currentuserid

                INTERSECT

                SELECT e.courseid
                FROM {user_enrolments} ue
                LEFT JOIN {enrol} e
                ON e.id = ue.enrolid
                WHERE ue.status = 0
                AND userid = :teacherid";

        $params = [
            'currentuserid' => $USER->id,
            'teacherid' => $teacherid
        ];

        if ($commoncourses = $DB->get_records_sql($sql, $params)) {
            // There is at least one common course.
            return true;
        }

        // No common courses, which means messaging is impossible.
        return false;
    }
}
