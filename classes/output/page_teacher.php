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
use local_musi\table\musi_table;
use mod_booking\singleton_service;
use moodle_url;
use renderer_base;
use renderable;
use stdClass;
use templatable;
use user_picture;

/**
 * This class prepares data for displaying a booking option instance
 *
 * @package local_musi
 * @copyright 2021 Georg Maißer {@link http://www.wunderbyte.at}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page_teacher implements renderable, templatable {

    /** @var stdClass $title */
    public $listofteachers = null;

    /**
     * In the Constructor, we gather all the data we need ans store it in the data property.
     */
    public function __construct(array $teacherids) {

        // We get the user objects of the provided teachers.
        $this->listofteachers = user_get_users_by_id($teacherids);

    }

    /**
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {
        global $PAGE;

        if (!isset($PAGE->context)) {
            $PAGE->set_context(context_system::instance());
        }

        $returnarray = [];

        // We transform the data object to an array where we can read key & value.
        foreach ($this->listofteachers as $teacher) {

            // Here we can load custom userprofile fields and add the to the array to render.
            // Right now, we just use a few standard pieces of information.

            // Get all booking options where the teacher is teaching and sort them by instance.
            $teacheroptiontables = $this->get_option_tables_for_teacher($teacher->id);


            $returnarray['teachers'][] = [
                'firstname' => $teacher->firstname,
                'lastname' => $teacher->lastname,
                'phone1' => $teacher->phone1,
                'description' => format_text($teacher->description, $teacher->descriptionformat),
                'optiontables' => $teacheroptiontables
            ];

            if ($teacher->picture) {
                $picture = new \user_picture($teacher);
                $picture->size = 150;
                $imageurl = $picture->get_url($PAGE);
                $returnarray['image'] = $imageurl;
            }
        }

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

        if (!empty($bookingidrecords)) {
            foreach ($bookingidrecords as $bookingidrecord) {

                $bookingid = $bookingidrecord->bookingid;

                if ($booking = singleton_service::get_instance_of_booking_by_bookingid($bookingid)) {

                    // Now create the table for each booking instance.
                    $tablename = bin2hex(random_bytes(12));
                    $table = new musi_table($tablename, $booking);

                    $fields = 'DISTINCT bo.*';
                    $from = '{booking_options} bo
                            LEFT JOIN {booking_teachers} bt
                            ON bo.id = bt.optionid';
                    $where = 'bo.bookingid = :bookingid
                            AND bt.userid = :teacherid
                            GROUP BY bo.id, bo.text
                            ORDER BY bo.text ASC';
                    $params = [
                        'bookingid' => $bookingid,
                        'teacherid' => $teacherid
                    ];

                    $table->set_sql($fields, $from, $where, $params);

                    $table->use_pages = false;
            
                    // $table->define_cache('nocache');
            
                    $table->add_subcolumns('itemcategory', ['sports']);
                    $table->add_subcolumns('itemday', ['dayofweek']);
                    $table->add_subcolumns('cardimage', ['image']);
            
                    $table->add_subcolumns('cardbody', ['sports', 'text', 'teacher']);
                    $table->add_classes_to_subcolumns('cardbody', ['columnkeyclass' => 'd-none']);
                    $table->add_classes_to_subcolumns('cardbody', ['columvalueclass' => 'h6'], ['sports']);
                    $table->add_classes_to_subcolumns('cardbody', ['columvalueclass' => 'h5'], ['text']);
            
                    $table->add_subcolumns('cardlist', ['dayofweek', 'location', 'bookings']);
                    $table->add_classes_to_subcolumns('cardlist', ['columnkeyclass' => 'd-none']);
                    $table->add_classes_to_subcolumns('cardlist', ['columniclassbefore' => 'fa fa-map-marker'], ['location']);
                    $table->add_classes_to_subcolumns('cardlist', ['columniclassbefore' => 'fa fa-clock-o'], ['dayofweek']);
                    $table->add_classes_to_subcolumns('cardlist', ['columniclassbefore' => 'fa fa-users'], ['bookings']);
            
                    $table->add_subcolumns('cardfooter', ['price']);
                    $table->add_classes_to_subcolumns('cardfooter', ['columnkeyclass' => 'd-none']);
            
                    $table->set_tableclass('cardimageclass', 'w-100');
            
                    $table->is_downloading('', 'List of booking options');
            
                    $table->tabletemplate = 'local_musi/shortcodes_cards';
            
                    ob_start();
                    $out = $table->out($perpage, true);
            
                    $out = ob_get_contents();
                    ob_end_clean();

                    // Add the table to the array of tables.
                    $teacheroptiontables[] = [
                        'bookingid' => $bookingid,
                        'bookinginstancename' => $booking->settings->name,
                        'tablename' => $tablename,
                        'table' => $out
                    ];
                }
            }
        }

        return $teacheroptiontables;
    }
}
