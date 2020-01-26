<?php
/**
 * Main application file.
 */

declare(strict_types = 1);

namespace TpCanvas;

require_once "global.php";

use PHPHtmlParser\Dom;

$log->info("Starting run");

/** @todo pass on debug flag to the http client */
$canvasclient = new GuzzleHttp\Client([
    'base_url' => "{$_SERVER['canvas_url']}api/v1/",
    'headers' => [
        'Authorization' => "Bearer {$_SERVER['canvas_key']}"
    ],
    /** @todo fix exception support */
    'http_errors' => false // We are not exception compliant :-/
]);

/**
 * Activerecord emulation wrapper class
 * @todo Replace with a proper wrapper
 */
class CanvasCourse
{
    public function __construct()
    {
    }
}

/**
 * Activerecord emulation wrapper class
 * @todo Replace with a proper wrapper
 */
class CanvasEvent
{
    public function __construct()
    {
    }

    static public createNew(string $responsedata['id'], object $db_course) {
        /** @todo Create new event record, add to chosen course */
    }
}

/**
 * Compare tp_event and canvas_event
 * Check for changes in title, location, start-date, end-date, staff and recording tag
 *
 * @param array $tp_event array from tp-ws
 * @param array $canvas_event array from canvas-ws
 * @param string $courseid Course id (e.g. INFO-1100). Required for title.
 *
 * @return bool wether the events was "equal"
 */
function tp_event_equals_canvas_event(array $tp_event, array $canvas_event, string $courseid) {
    /** @todo There was thread seppuku flag detection code here. Needs to be redone if threading is implemented. */

    // If event is marked as deleted in canvas, pretend it's not there
    if ($canvas_event['workflow_state']=='deleted') {
        return false;
    }

    // title
    $title = "";
    if (isset($tp_event['title']) && $tp_event['title']) {
        $title = "{$courseid} ({$tp_event['title']}) {$tp_event['summary']}";
    } else {
        $title = "{$courseid} {$tp_event['summary']}";
    }
    $title .= "\u200B\u200B";
    if ($title != $canvas_event['title']) {
        return false;
    }

    // location
    $location = '';
    if (isset($tp_event['room']) && $tp_event['room']) {
        $location = array_map(function($room) {
            return "{$room['buildingid']} {$room['roomid']}";
        }, $tp_event['room']);
        $location = implode(', ', $location);
    }
    if ($location != $canvas_event['location_name']) {
        return false;
    }

    // dates
    if (strtotime($tp_event['dtstart']) != strtotime($canvas_event['start_at'])) {
        return false;
    }
    if (strtotime($tp_event['dtend']) != strtotime($canvas_event['end_at'])) {
        return false;
    }

    // Fetch recording, curriculum and staff from canvas_event
    $dom = new Dom;
    $dom->load($canvas_event['description']);
    $meta = $dom->find('span#description-meta', 0);
    if (!$meta) {
        return false; // Missing meta? Pretend we're missing event.
    }
    $meta = json_decode($meta->text(true), true);

    // Staff array
    $staff_arr = array();
    if (isset($tp_event['staffs']) && is_array($tp_event['staffs'])) {
        $staff_arr = array_map(function ($staff) {
            return "{$staff['firstname']} {$staff['lastname']}";
        }, $tp_event['staffs']);
    }
    if (isset($tp_event['xstaff-list']) && is_array($tp_event['xstaff-list'])) {
        $staff_arr = array_merge($staff_arr, array_map(function ($staff) {
            return "{$staff['name']} (ekstern) {$staff['url']}";
        }, $tp_event['xstaff-list']));
    }
    sort($staff_arr);
    sort($meta['staff']);
    if ($staff_arr != $meta["staff"]) {
        return false;
    }

    // Recording tag
    /** @todo check if this logic checks out */
    $recording = false;
    if (isset($tp_event['tags']) && is_array($tp_event['tags'])) {
        $tags = preg_grep('/Mediasite/', $tp_event['tags']);
        if (count($tags) > 0) {
            $recording = true;
        }
    }
    if ($recording != $meta['recording']) {
        return false;
    }

    // Curriculum
    /** @todo check string format here */
    if (md5($tp_event['curr']) != $meta['curr']) {
        return false;
    }

    return true;
}

/**
 * Create event in Canvas and database
 *
 * @param array $event The event definition (from tp)
 * @param object $db_course The course db object to add event to
 * @param string $courseid
 * @param string $canvas_course_id
 *
 * @return void
 *
 * @todo Ensure result is returned properly
 */
function add_event_to_canvas(array $event, object $db_course, string $courseid, string $canvas_course_id) {
    global $canvasclient;

    /** @todo There was thread seppuku flag detection code here. Needs to be redone if threading is implemented. */

    // Mazemap location
    $location = '';
    $map_url = '';
    if (isset($event['room']) && is_array($event['room'])) {
        $location = array_map(function($room) {
            return "{$room['buildingid']} {$room['roomid']}";
        }, $event['room']);
        $location = implode(', ', $location);
        foreach ($event['room'] as $room) {
            $room_name="{$room['buildingid']} {$room['roomid']}";
            $room_url="https://uit.no/mazemaproom?room_name=".urlencode($room_name)."&zoom=20";
            $map_url .= "<a href={$room_url}> {$room_name}</a><br>";
        }
    }

    // Staff array
    $staff_arr = array();
    if (isset($event['staffs']) && is_array($event['staffs'])) {
        $staff_arr = array_map(function ($staff) {
            return "{$staff['firstname']} {$staff['lastname']}";
        }, $event['staffs']);
    }
    if (isset($event['xstaff-list']) && is_array($event['xstaff-list'])) {
        $staff_arr = array_merge($staff_arr, array_map(function ($staff) {
            return "{$staff['name']} (ekstern) {$staff['url']}";
        }, $event['xstaff-list']));
    }

    // Staff string
    $staff = "";
    if (isset($event['staffs']) && is_array($event['staffs'])) {
        $staff = array_map(function ($staffp) {
            return "{$staffp['firstname']} {$staffp['lastname']}";
        }, $event['staffs']);
    }
    if (isset($event['xstaff-list']) && is_array($event['xstaff-list'])) {
        $staff = array_merge($staff_arr, array_map(function ($staffp) { 
            if ($staffp['url'] != '') {
                return "<a href='{$staffp['url']}'>{$staffp['name']} (ekstern)</a>";
            }
            return "{$staffp['name']} (ekstern) {$staffp['url']}";
        }, $event['xstaff-list']));
    }
    $staff = implode("<br>", $staff);

    // Recording tag
    /** @todo check if this logic checks out */
    $recording = false;
    if (isset($event['tags']) && is_array($event['tags'])) {
        $tags = preg_grep('/Mediasite/', $event['tags']);
        if (count($tags) > 0) {
            $recording = true;
        }
    }

    // Title
    $title = '';
    if (isset($event['title']) && $event['title']) {
        $title = "{$courseid} ({$event['title']}) {$event['summary']}";
    } else {
        $title = "{$courseid} {$event['summary']}";
    }
    $title .= "\u200B\u200B";

    $curr = $event['curr'];
    $editurl = $event['editurl'];
    $description_meta = array(
        'recording' => $recording,
        'staff' => $staff_arr,
        'curr' => md5($curr)
    );

    // Send to Canvas
    $response = $canvasclient->request('POST', 'calendar_events.json', [
        'json' => [
            'calendar_event' => [
                'context_code' => "course_{$canvas_course_id}",
                'title' => $title,
                'description' => erb_description(),
                'start_at' => $event['dtstart'],
                'end_at' => $event['dtend'],
                'location_name' => $location
            ]
        ]
    ]);

    // Save to database if ok
    if ($response.getStatusCode() == 201) {
        $responsedata = json_decode($response.getBody(), true);
        CanvasEvent::createNew($responsedata['id'], $db_course);    
    }
    /** @todo error handling and retries */
}
