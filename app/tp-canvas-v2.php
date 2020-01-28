<?php
/**
 * Main application file.
 */

declare(strict_types = 1);

namespace TpCanvas;

require_once "global.php";

use PHPHtmlParser\Dom;

$log->info("Starting run");

$canvasHandlerStack = GuzzleHttp\HandlerStack::create();
$canvasHandlerStack->push(GuzzleHttp\Middleware::retry(retryDecider(), retryDelay()));
/** @todo pass on debug flag to the http client */
$canvasclient = new GuzzleHttp\Client([
    'base_url' => "{$_SERVER['canvas_url']}api/v1/",
    'headers' => [
        'Authorization' => "Bearer {$_SERVER['canvas_key']}"
    ],
    'handler' => $canvasHandlerStack,
    /** @todo fix exception support */
    'http_errors' => false // We are not exception compliant :-/
]);

$tpHandlerStack = GuzzleHttp\HandlerStack::create();
$tpHandlerStack->push(GuzzleHttp\Middleware::retry(retryDecider(), retryDelay()));
/** @todo pass on debug flag to the http client */
$tpclient = new GuzzleHttp\Client([
    'base_url' => "{$_SERVER['tp_url']}ws/",
    'headers' => [
        'X-Gravitee-Api-Key' => $_SERVER['tp_key']
    ],
    'handler' => $tpHandlerStack,
    /** @todo fix exception support */
    'http_errors' => false // We are not exception compliant :-/
]);

function retryDecider()
{
    return function (
        $retries,
        Request $request,
        Response $response = null,
        RequestException $exception = null
    ) {
       // Limit the number of retries to 5
        if ($retries >= 5) {
            return false;
        }

        // Retry connection exceptions
        if ($exception instanceof ConnectException) {
            return true;
        }

        if ($response) {
            // Retry on server errors
            if ($response->getStatusCode() >= 500) {
                return true;
            }
        }
        return false;
    };
}
function retryDelay()
{
    return function ($numberOfRetries) {
        return 1000 * $numberOfRetries;
    };
}

/**
 * Activerecord emulation wrapper class
 * @todo Replace with a proper wrapper
 * @todo add properties from schema
 */
class CanvasCourse
{
    public function __construct()
    {
    }

    public static function find_or_create(string $courseid)
    {
        /** @todo find or create new course */
    }
    public static function find(string $courseid) {
        /** @todo find course by sis id */
    }
    public static function findBySisLike(string $like) {
        /** @todo find courses where sis_course_id like '$like' */
    }
    public function delete()
    {
        /** @todo Delete event */
    }
    public function save()
    {
        /** @todo save event */
    }
    public function remove_all_canvas_events() {
        /** @todo remove all canvas events for this course */
    }
}

/**
 * Activerecord emulation wrapper class
 * @todo Replace with a proper wrapper
 * @todo add properties from schema
 */
class CanvasEvent
{
    public function __construct()
    {
    }

    public static function createNew(string $canvasid, object $db_course)
    {
        /** @todo Create new event record, add to chosen course */
    }
    public function delete()
    {
        /** @todo Delete event */
    }
    public function save()
    {
        /** @todo save event */
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
function tp_event_equals_canvas_event(array $tp_event, array $canvas_event, string $courseid)
{
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
        $location = array_map(function ($room) {
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
function add_event_to_canvas(array $event, object $db_course, string $courseid, string $canvas_course_id)
{
    global $log, $canvasclient;

    /** @todo There was thread seppuku flag detection code here. Needs to be redone if threading is implemented. */

    // Mazemap location
    $location = '';
    $map_url = '';
    if (isset($event['room']) && is_array($event['room'])) {
        $location = array_map(function ($room) {
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
    $response = $canvasclient->post('calendar_events.json', [
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
        $log->info("Event created in Canvas: {$title} - canvas id: {$responsedata['id']} - internal id: xxx");
    }
    /** @todo error handling and retries */
}

/**
 * Delete single canvas event (db and canvas)
 *
 * @param object event database object of event
 *
 * @return void
 * @todo implement error returns
 */
function delete_canvas_event(object $event)
{
    global $log, $canvasclient;
    /** @todo There was thread seppuku flag detection code here. Needs to be redone if threading is implemented. */

    $response = $canvasclient->delete("calendar_events/{$event->canvas_id}.json");
    if ($response.getStatusCode() == 200) { // OK
        event.delete();
        $log->info("Event deleted in Canvas", ['event' => $event]);
    } elseif ($response.getStatusCode() == 404) { // NOT FOUND
        event.delete();
        $log->warning("Event missing in Canvas", ['event'=>$event]);
    } elseif ($response->getStatusCode() == 401) { // UNAUTHORIZED
        // Is the event deleted in canvas?
        $response = $canvasclient->get("calendar_events/{$event->canvas_id}.json");
        $responsedata = json_decode($response.getBody(), true);
        if ($responsedata['workflow_state'] == 'deleted') {
            event.delete();
            $log->warning("Event marked as deleted in Canvas", ['event'=>$event]);
        } else {
            $log->error("Unable to delete event in Canvas", ['event'=>$event]);
        }
    } else {
        $log->error("Unable to delete event in Canvas", ['event'=>$event, 'error'=>$response->getStatusCode()]);
    }
}

/**
 * Delete all Canvas events for a course (db and canvas)
 *
 * @param object $course database course object
 *
 * @return void
 * @todo implement error returns
 */
function delete_canvas_events(object $course)
{
    /** @todo this iterates over a magic property - needs implementation somehow */
    foreach ($course->canvas_events as $event) {
        delete_canvas_event($event);
    }
}

/**
 * Generic method to add a tp-timetable to one or more canvas courses
 *
 * @param array $courses canvas courselist from json
 * @param array $tp_activities tp timetable from json
 * @param string $courseid (e.g INF-1100)
 *
 * @return void
 */
function add_timetable_to_canvas(object $courses, object $tp_activities, string $courseid)
{
    if (!$tp_activities) {
        return;
    }

    foreach ($courses as $course) {
        // Find matching timetable
        $actid = explode('_', $course['sis_course_id']);
        $actid = end($actid);
        $act_timetable = array_filter($tp_activities, function ($tp_act) use ($actid) {
            return ($tp_act['actid'] == $actid);
        });
        add_timetable_to_one_canvas_course($course, $act_timetable, $courseid);
    }
}

/**
 * Add a tp timetable to one canvas course
 *
 * @param array $canvas_course from canvas json
 * @param array $timetable from tp json
 * @param string $courseid (e.g INF-1100)
 *
 * @return void
 * @todo waaay too many error conditions to return
 */
function add_timetable_to_one_canvas_course(array $canvas_course, array $timetable, string $courseid)
{
    global $log, $canvasclient;

    $db_course = CanvasCourse::find_or_create($canvas_course['id']);
    $db_course->name = $canvas_course['name'];
    $db_course->course_code = $canvas_course['course_code'];
    $db_course->sis_course_id = $canvas_course['sis_course_id'];
    $db_course.save();

    // Empty tp-timetable
    if (!$timetable) {
        delete_canvas_events($db_course);
        return;
    }

    // put tp-events in array (unnesting)
    $tp_events = array();
    foreach ($timetable as $t) {
        foreach ($t['eventsequences'] as $eventsequence) {
            foreach ($eventsequence['events'] as $event) {
                $tp_events[] = $event;
            }
        }
    }

    // fetch canvas events found in db
    /** @todo uses magic property - needs implementation */
    foreach ($db_course->canvas_events as $canvas_event_db) {
        /** @todo no error checking! */
        $response = $canvasclient->get("calendar_events/{$canvas_event_db->canvas_id}.json");
        $canvas_event_ws = json_decode($response.getBody(), true);
        $found_matching_tp_event = false;

        // Look for match between canvas and tp
        foreach ($tp_events as $i => $tp_event) {
            if (tp_event_equals_canvas_event($tp_event, $canvas_event_ws, $courseid)) {
                // No need to update, remove tp_event from array of events
                unset($tp_events[$i]);
                $found_matching_tp_event = true;
                $log->info(
                    "Event match in TP and Canvas - no update needed",
                    [
                        'db_course' => $db_course,
                        'canvas_event_db' => $canvas_event_db
                    ]
                );
                break;
            }
        }

        if (!$found_matching_tp_event) {
            // Nothing matched in tp, this event has been deleted from tp
            delete_canvas_event($canvas_event_db);
        }
    }

    // Add remaining tp-events in canvas
    foreach ($tp_events as $event) {
        add_event_to_canvas($event, $db_course, $courseid, $canvas_course['id']);
    }
}

/**
 * Remove local courses that have been removed from Canvas
 *
 * @param array $canvas_courses
 *
 * @return void
 */
function remove_local_courses_missing_from_canvas(array $canvas_courses)
{
    foreach ($canvas_courses as $course_id) {
        $local_course = CanvasCourse::find($course_id);
        $local_course.remove_all_canvas_events();
        $local_course.delete();
    }
}

/**
 * Check for structural changes in canvas courses.
 * Only called explicitly from command line - called in cronjob
 * 
 * @param string $semester Semester string "YY[h|v]" e.g "18v"
 */
function check_canvas_structure_change($semester)
{
    global $log, $tpclient;

    // Fetch all active courses from TP
    $tp_courses = $tpclient->get("course", ['id' => $_SERVER['tp_institution'], 'sem' => $semester, 'times' => 1]);
    if ($tp_courses.getStatusCode() != 200) {
        $log->critical("Could not get course list from TP", array($semester));
        return;
    }
    $tp_courses = json_decode($tp_courses.getBody(), true);

    // For each course in tp...
    foreach ($tp_courses['data'] as $tp_course) {
        // Create Canvas SIS string
        $sis_semester = make_sis_semester($semester, $tp_course['terminnr']);
        // Fetch course candidates from Canvas
        $canvas_courses = fetch_and_clean_canvas_courses($tp_course['id'], $semester, $tp_course['terminnr'], false);
        $canvas_courses_ids = array_column($canvas_courses, 'sis_course_id');

        // ? Seems to collect sis_course_id for all courses that we have touched that matches
        /** @todo verify this like syntax */
        $local_courses = CanvasCourse::findBySisLike("%{$tp_course['id']}\\_%\\_{$sis_semester}%");
        $local_courses = array_column($local_courses, 'sis_course_id');

        // Gather id's that only exist in one of the arrays?
        $local_diff = array_diff($local_courses, $canvas_courses_ids);
        $canvas_diff = array_diff($canvas_courses_ids, $local_courses);
        $diff = array_merge($local_diff, $canvas_diff);

        if ($local_diff) {
            // Local courses that does not exist in canvas any more
            remove_local_courses_missing_from_canvas($local_diff);
            $log->warning('Local course removed from canvas', ['tp_course' => $tp_course, 'semester' => $semester]);
            /** @todo there used to be a sentry event here as well? */
        }

        if ($diff) {
            // Courses in canvas that we have no trace of locally
            /** @todo should this really have been $canvas_diff ? */
            $log->warning('Course changed in canvas and need to update', ['tp_course' => $tp_course, 'semester' => $semester]);
        }
    }
}
