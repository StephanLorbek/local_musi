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
 * Add dates to option.
 *
 * @package local_musi
 * @copyright 2022 Bernhard Fischer <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_musi\sap_daily_sums;

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

// No guest autologin.
require_login(0, false);

global $DB, $PAGE, $OUTPUT, $USER;

if (!$context = context_system::instance()) {
    throw new moodle_exception('badcontext');
}

if ((has_capability('mod/booking:updatebooking', $context) || has_capability('mod/booking:addeditownoption', $context)) == false) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('accessdenied', 'mod_booking'), 4);
    echo get_string('nopermissiontoaccesspage', 'mod_booking');
    echo $OUTPUT->footer();
    die();
}

// Check if optionid is valid.
$PAGE->set_context($context);

$title = 'SAP-Textdateien mit Tagessummen';

$PAGE->set_url('/local/musi/createsapdailysums.php');
$PAGE->navbar->add($title);
$PAGE->set_title(format_string($title));

$PAGE->set_heading($title);
$PAGE->set_pagelayout('standard');

echo $OUTPUT->header();

$now = time(); // Current timestamp.
$yesterday = strtotime('-1 day', $now);
$dateoneyearago = strtotime('-365 days', $now);

$fs = get_file_storage();

$contextid = $context->id;
$component = 'local_musi';
$filearea = 'musi_sap_dailysums';
$itemid = 0;
$filepath = '/';

$starttimestamp = $dateoneyearago;
while ($starttimestamp <= $yesterday) {
    $filename = 'SAP_USI_' . date('Ymd', $starttimestamp) . '.txt';

    // Retrieve the file from the Files API.
    $file = $fs->get_file($contextid, $component, $filearea, $itemid, $filepath, $filename);
    if (!$file) {
        $fileinfo = array(
            'contextid' => $contextid,
            'component' => $component,
            'filearea' => $filearea,
            'itemid' => $itemid,
            'filepath' => $filepath,
            'filename' => $filename
        );

        list($content, $errorcontent) = sap_daily_sums::generate_sap_text_file_for_date(date('Y-m-d', $starttimestamp));
        $fs->create_file_from_string($fileinfo, $content);

        // If we have error content, we create an error file.
        if (!empty($errorcontent)) {
            $errorfilename = 'SAP_USI_' . date('Ymd', $starttimestamp) . '_errors.txt';
            $errorfile = $fs->get_file($contextid, $component, $filearea, $itemid, $filepath, $errorfilename);
            if (!$errorfile) {
                $errorfileinfo = array(
                    'contextid' => $contextid,
                    'component' => $component,
                    'filearea' => $filearea,
                    'itemid' => $itemid,
                    'filepath' => $filepath,
                    'filename' => $errorfilename
                );
                $fs->create_file_from_string($errorfileinfo, $errorcontent);
            }

        }
    }
    $starttimestamp = strtotime('+1 day', $starttimestamp);
}

// List all existing files as links.
$files = $fs->get_area_files($context->id, 'local_musi', 'musi_sap_dailysums');

$dataforsapfiletemplate = [];
foreach ($files as $file) {
    $filename = $file->get_filename();
    if ($filename == '.') {
        continue;
    }
    $filenamearr = explode('_', $filename);
    $datepart = trim($filenamearr[2], '.txt');
    $year = substr($datepart, 0, 4);
    $month = substr($datepart, 4, 2);

    $url = moodle_url::make_pluginfile_url(
        $contextid,
        $component,
        $filearea,
        $itemid,
        $filepath,
        $filename,
        true // Force download of the file.
    );
    $currentlink = html_writer::link($url, $filename);
    //echo html_writer::link($url, $filename);
    //echo '<br/>';

    // We collect all links per month, so we can show it in a nice way.

    // It's a new year.
    if (!isset($dataforsapfiletemplate['year'])) {
        $dataforsapfiletemplate = ['year' => [$year => ['month' => [$month => ['link' => [$currentlink]]]]]];
    } else if (!isset($dataforsapfiletemplate['year'][$year]['month'])) {
        // It's a new month.
        $dataforsapfiletemplate['year'][$year] = ['month' => [$month => ['link' => [$currentlink]]]];
    } else {
        // Else we can just add the link to the existing link array.
        $dataforsapfiletemplate['year'][$year]['month'][$month]['link'][] = $currentlink;
    }

    // If we want to delete all files, we can use this line.
    // $file->delete(); // Workaround: delete files.
}

echo build_sapfiles_accordion($dataforsapfiletemplate);
echo $OUTPUT->footer();

/**
 * Helper function to build the SAP files accordion.
 * @param array $dataforsapfiletemplate
 * @return string the html
 */
function build_sapfiles_accordion(array $dataforsapfiletemplate) {

    $html = '';
    $html .=
    '<div id="sapfiles-years-accordion">
        <div class="sapfiles-year">';

    foreach ($dataforsapfiletemplate['year'] as $y => $val) {
        $html .=
        '<div class="card-header" id="heading' . $y . '}">
            <h5 class="mb-0">
                <button class="btn btn-link" data-toggle="collapse" data-target="#collapse' . $y . '" aria-expanded="true"
                    aria-controls="collapse' . $y . '">
                    ' . $y . '
                </button>
            </h5>
        </div>
        <div id="collapse' . $y . '" class="collapse show" aria-labelledby="heading' . $y .
            '" data-parent="#sapfiles-years-accordion">
            <div class="card-body">';

        $html .=
        '<div id="sapfiles-' . $y . '-months-accordion">
            <div class="sapfiles-month">';

        foreach ($dataforsapfiletemplate['year'][$y]['month'] as $m => $val) {
            $html .= '<div class="card-header" id="heading' . "$y-$m" . '}">
                <h5 class="mb-0">
                    <button class="btn btn-link" data-toggle="collapse" data-target="#collapse' . "$y-$m" . '" aria-expanded="true"
                        aria-controls="collapse' . "$y-$m" . '">
                        ' . "$m" . '
                    </button>
                </h5>
            </div>
            <div id="collapse' . "$y-$m" . '" class="collapse show" aria-labelledby="heading' . "$y-$m" .
                '" data-parent="#sapfiles-' . "$y-$m" . '-months-accordion">
                <div class="card-body">';

            $html .= 'month content...'; // TODO: Links!

            $html .= '</div></div>';
        }

        $html .= '</div></div></div></div>';
    }

    $html .= '</div></div>';

    return $html;
}
