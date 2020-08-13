<?php declare(strict_types=1);

/**
 * Main application file.
 */

namespace TpCanvas;

require_once "global.php";

use PHPHtmlParser;
use PhpAmqpLib;
use GuzzleHttp;
use \Exception;

#region main

$log->info("Starting run");

$canvasclient = new CanvasClient($_SERVER['canvas_url'], $_SERVER['canvas_key']);
$tpclient = new TPClient($_SERVER['tp_url'], $_SERVER['tp_key'], (int) $_SERVER['tp_institution']);
$canvas = new Canvas($canvasclient, $log);
$pdoclient = new \PDO($_SERVER['db_dsn'], $_SERVER['db_user'], $_SERVER['db_password']);

if (!isset($argv[1])) {
    $argv[1] = '';
}

$argnums = [
    'semester' => 1,
    'course' => 3,
    'removecourse' => 3,
    'mq' => 0,
    'canvasdiff' => 1,
    'compareenvironments' => 1,
    'diagnosecourse' => 4,
    'deleteevent' => 2,
    'coursemap' => 3,
    'cleancal' => 1
];

if (isset($argnums[$argv[1]])) {
    if (count($argv) != ($argnums[$argv[1]] + 2)) {
        echo "Error: Wrong number of arguments!\n";
        exit;
    }
}

switch ($argv[1]) {
    case 'semester':
        cmd_semester($argv[2]);
        break;
    case 'course':
        cmd_course($argv[2], $argv[3], (int) $argv[4]);
        break;
    case 'removecourse':
        cmd_removecourse($argv[2], $argv[3], (int) $argv[4]);
        break;
    case 'mq':
        cmd_mq();
        break;
    case 'canvasdiff':
        cmd_canvasdiff($argv[2]);
        break;
    case 'compareenvironments':
        cmd_compareenvironments($argv[2]);
        break;
    case 'diagnosecourse':
        cmd_diagnosecourse($argv[2], (int) $argv[3], $argv[4], (int) $argv[5]);
        break;
    case 'deleteevent':
        cmd_deleteevent((int) $argv[2], (int) $argv[3]);
        break;
    case 'coursemap':
        cmd_coursemap($argv[2], $argv[3], (int)$argv[4]);
        break;
    case 'cleancal':
        cmd_cleancal($argv[2]);
        break;
    default:
        echo "Command-line utility to sync timetables from TP to Canvas.\n";
        echo "Usage: {$argv[0]} [command] [options]\n";
        echo "  Add full semester: semester 18h\n";
        echo "  Add course: course MED-3601 18h 1\n";
        echo "  Remove course from Canvas: removecourse MED-3601 18h 1\n";
        echo "  Process changes from AMQP: mq\n";
        echo "  Check for Canvas change: canvasdiff 18h\n";
        echo "  Compare prod and test: compareenvironments 2020-01-21T00:00:00\n";
        echo "  Diagnose a course: diagnosecourse MED-3601 123456 20v 1\n";
        echo "  Delete a single Canvas event: deleteevent 123 12345\n";
        echo "  Output mapping for a course: coursemap MED-3601 20v 2\n";
        echo "  Clean automatically created calendar events after a date: cleancal 2020-08-01\n";
        break;
}
exit;

#endregion main

#region commands

/**
 * Update entire semester in Canvas
 *
 * @param string $semester "YY[h|v]" e.g "18v"
 * @return void
 */
function cmd_semester(string $semester)
{
    global $log, $tpclient;

    $log->info("Starting full sync", ['semester' => $semester]);

    // Fetch all active courses from TP
    try {
        $tp_courses = $tpclient->courses($semester, 1);
    } catch (\RuntimeException $e) {
        $log->critical("Could not get course list from TP", ['semester' => $semester, 'e' => $e]);
        return;
    }

    foreach ($tp_courses->data as $tp_course) {
        // Stupid thread argument wrapping end
        $log->info("Updating one course", ['course' => $tp_course]);
        cmd_course($tp_course->id, $semester, $tp_course->terminnr);
        /** @todo error handling here? */
    }
}

/**
 * Update one course in Canvas
 * This is the function called for change events from rabbitmq. It is also
 * called from check_canvas_structure() (in turn called from cronjob) and
 * cmd_semester() (for every single active course in tp).
 *
 * @param string $courseid e.g "INF-1100"
 * @param string $semesterid e.g "18v"
 * @param int $termnr
 * @return bool operation ok (if not, retry)
 */
function cmd_course(string $courseid, string $semesterid, int $termnr): bool
{
    global $log;

    $timetable = fetchTPSchedule($courseid, $semesterid, $termnr);

    if (!$timetable) {
        $log->error("Course not found in TP", ['courseid' => $courseid, 'semester' => $semesterid, 'term' => $termnr]);
        return false;
    }

    // Fetch courses from canvas
    $canvas_courses = fetch_and_clean_canvas_courses($courseid, $semesterid, $termnr);
    if (empty($canvas_courses)) {
        $log->notice("Found no matching canvas course", [
            'course' => $courseid,
            'semester' => $semesterid,
            'termin' => $termnr
        ]);
        // This is ok, a lot of courses don't exist in Canvas
        return true;
    }

    if (count($canvas_courses) == 1) { // Only one course in canvas, everything goes in here
        // This only triggers the first year a course is used
        // Just merge group and plenary to a single array
        $tdata = [];
        if (isset($timetable->data)) {
            if (isset($timetable->data->group)) {
                $tdata = array_merge($tdata, $timetable->data->group);
            }
            if (isset($timetable->data->plenary)) {
                $tdata = array_merge($tdata, $timetable->data->plenary);
            }
        }
        $course = reset($canvas_courses);
        $log->debug("Found a 1-1 match", ['course' => $courseid, 'canvas' => $course]);
        add_timetable_to_one_canvas_course($course, $tdata, $timetable->courseid);
        return true;
    }

    // More than one course in Canvas - this is where the UA/UE magic happens
    // Find UE - several versions of a course might pose a problem here
    $ue = array_filter($canvas_courses, function (object $course) {
        if (stripos($course->sis_course_id, 'UE_') === false) {
            return false;
        }
        return true;
    });
    if (count($ue)>1) {
        $log->notice(
            "More than one UE matched in Canvas",
            ['course' => $courseid, 'semester' => $semesterid, 'termnr' => $termnr]
        );
    }
    // Find UA
    $ua = array_filter($canvas_courses, function (object $course) {
        if (stripos($course->sis_course_id, 'UA_') === false) {
            return false;
        }
        return true;
    });

    $plenary_timetable = [];
    if (isset($timetable->data) && isset($timetable->data->plenary)) {
        $plenary_timetable = $timetable->data->plenary;
    }

    $group_timetable = [];
    if (isset($timetable->data) && isset($timetable->data->group)) {
        $group_timetable = $timetable->data->group;
    }

    $log->debug("Ready to update multicourse", [
        'canvas ue' => array_column($ue, 'sis_course_id'),
        'tp plenary' => array_column($plenary_timetable, 'id'),
        'canvas ua' => array_column($ua, 'sis_course_id'),
        'tp group' => array_column($group_timetable, 'id')
    ]);

    if (count($ue)) {
        add_timetable_to_one_canvas_course(reset($ue), $plenary_timetable, $timetable->courseid);
    }

    if (count($ua)) {
        add_timetable_to_canvas($ua, $group_timetable, $timetable->courseid);
    }
    return true;
}

/**
 * Remove events for one tp course from canvas
 * Called explicitly from commandline
 *
 * @param string $courseid
 * @param string $semesterid
 * @param int $termnr
 * @return void
 */
function cmd_removecourse(string $courseid, string $semesterid, int $termnr)
{
    $sis_semester = make_sis_semester($semesterid, $termnr);

    /** @todo verify this like condition */
    $courses = CanvasDbCourse::findBySisLike("%{$courseid}\\_%\\_{$sis_semester}%");
    foreach ($courses as $course) {
        delete_canvas_events($course);
    }
}

/**
 * Subscribe to message queue and update when courses change
 * This is what runs as a service
 *
 * @return void
 */
function cmd_mq()
{
    global $log;

    while (true) {
        /** @todo there needs to be more error-checking going on here */
        try {
            $connection = new PhpAmqpLib\Connection\AMQPStreamConnection(
                $_SERVER['mq_host'],
                5672,
                $_SERVER['mq_user'],
                $_SERVER['mq_password']
            );
            $channel = $connection->channel();
        } catch (PhpAmqpLib\Exception\AMQPIOException $e) {
            $log->error("Error connecting to mq host", ['exception' => $e]);
            sleep(60*5); // 5 minutes wait
            continue; // Let's try again
        }
        /** @todo $channel->prefetch(1) */
        $channel->exchange_declare($_SERVER['mq_exchange'], 'fanout', false, true, false);
        list($queue_name, ,) = $channel->queue_declare($_SERVER['mq_queue'], false, true, false, false);
        $channel->queue_bind($queue_name, $_SERVER['mq_exchange']);
        $channel->basic_consume($queue_name, '', false, false, false, false, "TpCanvas\\queue_process");
    
        while ($channel->is_consuming()) {
            try {
                $channel->wait();
            } catch (PhpAmqpLib\Exception\AMQPProtocolChannelException $e) {
                $log->error("Protocol Channel Exception", ['exception' => $e]);
            } catch (PhpAmqpLib\Exception\AMQPConnectionClosedException $e) {
                $log->error("Connection Closed Exception", ['exception' => $e]);
            }
        }
    
        $log->info("Main loop cleanup");
        $channel->close();
        $connection->close();
        sleep(5); // 5 seconds grace period before reconnecting
    }
}

/**
 * Check for structural changes in canvas courses.
 * Only called explicitly from command line - called in cronjob
 *
 * @param string $semester Semester string "YY[h|v]" e.g "18v"
 * @return void
 */
function cmd_canvasdiff(string $semester)
{
    global $log, $tpclient;

    // Fetch all active courses from TP
    try {
        $tp_courses = $tpclient->courses($semester, 1);
    } catch (\RuntimeException $e) {
        $log->critical("Could not get course list from TP", ['semester' => $semester, 'e' => $e]);
        return;
    }

    // For each course in tp...
    foreach ($tp_courses->data as $tp_course) {
        // Create Canvas SIS string
        $sis_semester = make_sis_semester($semester, $tp_course->terminnr);
        // Fetch course candidates from Canvas
        $canvas_courses = fetch_and_clean_canvas_courses($tp_course->id, $semester, $tp_course->terminnr);
        $canvas_courses_ids = array_column($canvas_courses, 'sis_course_id');

        // ? Seems to collect sis_course_id for all courses that we have touched that matches
        /** @todo verify this like syntax */
        $local_courses = CanvasDbCourse::findBySisLike("%{$tp_course->id}\\_%\\_{$sis_semester}%");
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
            cmd_course($tp_course->id, $semester, $tp_course->terminnr);
            $log->warning('Course changed in canvas and need to update', ['tp_course' => $tp_course, 'semester' => $semester]);
        }
    }
}

/**
 * Compare production and test, outputting differences
 * @param string $timestamp timestamp for last environment update in ISO-8601 format e.g "2020-01-21T00:00:00"
 */
function cmd_compareenvironments(string $timestamp)
{
    global $log;

    $canvastest = new CanvasClient('https://uit.test.instructure.com/', $_SERVER['canvas_key']);
    $canvasprod = new CanvasClient('https://uit.instructure.com/', $_SERVER['canvas_key']);
    $tpclient = new TPClient($_SERVER['tp_url'], $_SERVER['tp_key'], (int) $_SERVER['tp_institution']);
    $courselist = $tpclient->lastchangedlist($timestamp);
    
    // For each course in our list
    foreach ($courselist as $course) {
        // Fetch matches from both environments, filter to courses present in both.
        if ($course->id == "BOOKING") {
            // Dunno why TP returns this, skip it...
            continue;
        }
        try {
            $coursest = fetchCourses($canvastest, $course->id);
            $coursesp = fetchCourses($canvasprod, $course->id);
        } catch (RuntimeException $e) {
            // Abort this course. See if the next is any better.
            $log->error("Could not fetch Canvas candidates - skipping", ['course' => $course->id, 'exception' => $e]);
            break;
        }
        $courses_not = array_merge(
            array_diff_key($coursest, $coursesp),
            array_diff_key($coursesp, $coursest)
        );

        $courses = array_intersect_key($coursest, $coursesp);
        if (count($courses_not)) {
            $log->info("Courses not matching", ['courses' => $courses_not]);
        }
        if (empty($courses)) {
            $log->info("No matching courses", ['course' => $course]);
            break;
        }
        foreach ($courses as $course) {
            // Fetch all calendar items
            try {
                $eventst = $canvastest->calendar_events(['context_codes[]' => "course_{$course->id}"]);
                $eventsp = $canvasprod->calendar_events(['context_codes[]' => "course_{$course->id}"]);
            } catch (RuntimeException $e) {
                // Abort this course. See if the next is any better.
                $log->error("Could not fetch Canvas events - skipping", [
                    'course' => $course->sis_course_id,
                    'exception' => $e
                ]);
                break;
            }
    
            foreach ($eventsp as $pkey => $pevent) {
                foreach ($eventst as $tkey => $tevent) {
                    if (CanvasClient::eventsEqual($pevent, $tevent)) {
                        // If equal, remove from both lists
                        unset($eventst[$tkey]);
                        unset($eventsp[$pkey]);
                    }
                }
            }
    
            if (count($eventst) || count($eventsp)) {
                $log->info("Differences in course", [
                    'course' => $course->sis_course_id,
                    'test' => $eventst,
                    'prod' => $eventsp
                ]);
            }
        }
    }
}

/**
 * Diagnose a course
 *
 * @param string $courseid e.g MED3601
 * @param string $canvasid e.g. 1232355
 * @param string $semesterid e.g. 20v
 * @param string $termnr e.g. 1
 * @return void
 */
function cmd_diagnosecourse(string $courseid, int $canvasid, string $semesterid, int $termnr): void
{
    global $log, $canvasclient;

    $timetable = fetchTPSchedule($courseid, $semesterid, $termnr);

    if (!$timetable) {
        $log->error("Course not found in TP", ['courseid' => $courseid, 'semester' => $semesterid, 'term' => $termnr]);
        return;
    }

    $plenary_timetable = [];
    if (isset($timetable->data) && isset($timetable->data->plenary)) {
        $plenary_timetable = $timetable->data->plenary;
    }

    $group_timetable = [];
    if (isset($timetable->data) && isset($timetable->data->group)) {
        $group_timetable = $timetable->data->group;
    }

    $timetable = $plenary_timetable; // @todo this is oversimplified

    $canvas_course = $canvasclient->courses_get($canvasid);
    $db_course = CanvasDbCourse::find_or_create((int) $canvas_course->id);
    $db_course->name = $canvas_course->name;
    $db_course->course_code = $canvas_course->course_code;
    $db_course->sis_course_id = $canvas_course->sis_course_id;
    // Save removed

    // Empty tp-timetable, flush everything in Canvas
    if (!$timetable) {
        $log->info("Empty tp timetable! Aborting.\n");
        return;
    }

    // put tp-events in array (unnesting)
    $tp_events = array();
    foreach ($timetable as $t) {
        foreach ($t->eventsequences as $eventsequence) {
            foreach ($eventsequence->events as $event) {
                $tp_events[] = $event;
            }
        }
    }

    // fetch canvas events found in db
    foreach ($db_course->canvas_events as $canvas_event_db) {
        try {
            // Get the corresponding canvas object
            $canvas_event_ws = $canvasclient->calendar_events_get($canvas_event_db->canvas_id);
        } catch (\RuntimeException $e) {
            // Could not read from Canvas, skip to next
            // @todo This is probably not the correct way to handle it, should
            // the exception bubble up? What happens on a 404?
            $log->error("Could not read calendar from canvas", ['e' => $e, 'event' => $canvas_event_db]);
            break;
        }
        $found_tp_event = false;

        // Look for match between canvas and tp
        $leastdiffs = [];
        foreach ($tp_events as $i => $tp_event) {
            $diffs = tp_event_equals_canvas_event2($tp_event, $canvas_event_ws, $courseid);
            if (!count($diffs)) {
                // No need to update, remove tp_event from array of events
                unset($tp_events[$i]);
                $found_tp_event = true;
                $log->info(
                    "Event match in TP and Canvas - no update needed",
                    ['cevent' => cevent2str($canvas_event_ws)]
                );
                break;
            }
            if (count($diffs)) {
                // Differences found
                if (!count($leastdiffs)) {
                    $leastdiffs = $diffs;
                }
                if (count($leastdiffs) > count($diffs)) {
                    $leastdiffs = $diffs;
                }
            }
        }

        if (!$found_tp_event) {
            // Nothing matched in tp, this event has been deleted from tp
            $log->info("Orphaned canvas event, scheduled for deletion", [
                'cevent' => cevent2str($canvas_event_ws),
                'leastdiffs' => $leastdiffs
                ]);
        }
    }

    // Add remaining tp-events in canvas
    foreach ($tp_events as $event) {
        $log->info("TP event scheduled for canvas", [tpevent2str($event)]);
    }
}

/**
 * Delete a single canvas event
 *
 * @param int $canvas_event_id
 * @return void
 */
function cmd_deleteevent(int $courseid, int $eventid):void
{
    global $log, $canvasclient, $canvas;

    try {
        unset($canvas->accounts[1]->courses[$courseid]->calendarevents[$eventid]);
    } catch (Exception $e) {
        // If Canvas failed, we abort
        $log->error("Failed to delete event", ['id' => $eventid, 'exception' => $e]);
        return;
    }

    // Check if we have records of the event. We might, or might not care.
    $dbevent = CanvasDbEvent::getFromCanvasId($eventid);
    if (!$dbevent) {
        $log->error("Event not found in database!", ['id' => $eventid]);
        return;
    }

    $dbevent->delete();
    $log->info("Event was allegedly deleted", ['event' => $dbevent]);
}

/**
 * Output course mappings for one course
 *
 * @param string $courseid e.g "INF-1100"
 * @param string $semesterid e.g "18v"
 * @param int $termnr
 * @return void
 */
function cmd_coursemap(string $courseid, string $semesterid, int $termnr)
{
    global $canvas, $tpclient, $log;

    $schedule = new TpSchedule($tpclient, $log, '20v', 'BED-2032', 1);
    var_dump([
        'first' => $schedule->firstsemester,
        'firstt' => $schedule->firstterm,
        'last' => $schedule->lastsemester,
        'lastt' => $schedule->lastterm
        ]);
    print_r($schedule->shortstruct());
    $canvascourses = $canvas->accounts[1]->courses->find('BED-2032');
    //print_r($canvascourses);

    $groupmatch = [];
    foreach ($schedule->activities['group'] as $index => $activity) {
        // First attempt, scan for a perfect match
        foreach ($canvascourses as $canvascourse) {
            $sis_elements = getSISElements($canvascourse->getSISID());
            if (
                $sis_elements['type'] == 'UA'
                && $sis_elements['course'] == $schedule->sourceobject->courseid
                && $sis_elements['tpsemester'] == $schedule->firstsemester
                && $sis_elements['termnr'] == $schedule->firstterm // This is always 1
                && $sis_elements['actid'] == $activity->sourceobject->actid
                && $canvascourse->isPublished()
            ) {
                $groupmatch[$index] = $canvascourse->getSISID();
                continue 2; // Next activity
            }
        }
    }
    var_dump($groupmatch);
}

/**
 * Clean out calendar events
 * 
 * @param string $maxdate The date cutoff for integration data (ie "123098234)
 */
function cmd_cleancal(string $maxdate) {
    global $canvas, $log;
    $maxdatets = strtotime($maxdate);
    foreach ($canvas->accounts[1]->courses as $course) {
        //if (!isset($course->total_students) || $course->total_students == 0) {
        //    $log->debug("Course (skipping because 0 students)", ['course' => (string) $course]);
        //    continue;                
        //}
        if ((!isset($course->workflow_state)) || $course->workflow_state != 'available') {
            $log->debug("Course (skipping because not available)", ['course' => var_export($course, TRUE)]);
            continue;
        }
        if (!isset($course->term)) {
            $log->debug("Course (skipping because no term )", ['course' => var_export($course, TRUE)]);
            continue;
        }
        if ((!$course->term->start_at) || (!$course->term->end_at)) {
            $log->debug("Course (skipping because open term)", ['course' => var_export($course, TRUE)]);
            continue;
        }
        if (!(strtotime($course->term->end_at) > $maxdatets)) {
            $log->debug("Course (skipping)", ['course' => (string) $course, 'term' => $course->term]);
            continue;
        }
        $log->debug("Course (processing)", ['course' => (string) $course, 'term' => $course->term]);
        foreach ($course->calendarevents as $calendarevent) {
            // Is this an integration generated event?
            if (isset($calendarevent->description) && strpos($calendarevent->description,'<span id="description-meta" style="display:none">') !== FALSE) {
                // Is this event set to start after the given max date?
                if (isset($calendarevent->start_at) && strtotime($calendarevent->start_at) > $maxdatets) {
                    $log->debug("Event", ['event' => (string) $calendarevent]);
                } else {
                    $log->debug("Event (skipping because date in past)", ['event' => (string) $event]);
                }
            } else {
                $log->debug("Event (skipping because manuel event)", ['event' => (string) $event]);
            }
        }
        $course->calendarevents->emptyCache();
    }
}

/**

1. if type, course id, semester (year+season first semester), termnr (first semester), actnr matches

**/

#endregion commands

#region utilityfunctions

/**
 * Compare tp_event and canvas_event
 * Check for changes in title, location, start-date, end-date, staff and recording tag
 *
 * @param object $tp_event event from tp-ws
 * @param object $canvas_event event from canvas-ws
 * @param string $courseid Course id (e.g. INFO-1100). Required for title.
 * @return bool wether the events was "equal"
 */
function tp_event_equals_canvas_event(object $tp_event, object $canvas_event, string $courseid): bool
{
    global $log;

    // If event is marked as deleted in canvas, pretend it's not there
    if ($canvas_event->workflow_state == 'deleted') {
        return false;
    }

    $compare = tpToCanvasEvent($tp_event, $courseid, 0); // Missing canvas course id

    // Title
    if ($compare->title != $canvas_event->title) {
        return false;
    }

    // Location
    if ($compare->location_name != $canvas_event->location_name) {
        return false;
    }

    // Dates
    if (strtotime($compare->start_at) != strtotime($canvas_event->start_at)) {
        return false;
    }
    if (strtotime($compare->end_at) != strtotime($canvas_event->end_at)) {
        return false;
    }

    // Fetch recording, curriculum and staff from canvas_event
    $dom = new PHPHtmlParser\Dom;
    $dom->load($canvas_event->description);
    $meta = $dom->find('span#description-meta', 0);
    if (!$meta) {
        $log->warn("Missing meta from Canvas event", ['cevent' => $canvas_event]);
        return false; // Missing meta -> pretend we're missing event.
    }
    $meta = json_decode($meta->text(true));

    // Staff array
    $staff_arr = array();
    if (isset($tp_event->staffs) && is_array($tp_event->staffs)) {
        $staff_arr = array_map(function ($staff) {
            return "{$staff->firstname} {$staff->lastname}";
        }, $tp_event->staffs);
    }
    if (isset($tp_event->{'xstaff-list'}) && is_array($tp_event->{'xstaff-list'})) {
        $staff_arr = array_merge($staff_arr, array_map(function ($staff) {
            return "{$staff->name} (ekstern) {$staff->url}";
        }, $tp_event->{'xstaff-list'}));
    }
    sort($staff_arr);
    sort($meta->staff);
    if ($staff_arr != $meta->staff) {
        return false;
    }

    // Recording tag
    $recording = false;
    if (isset($tp_event->tags) && is_array($tp_event->tags)) {
        $tags = preg_grep('/Mediasite/', $tp_event->tags);
        if (count($tags) > 0) {
            $recording = true;
        }
    }
    if ($recording != $meta->recording) {
        return false;
    }

    // Curriculum
    if (md5($tp_event->curr ?? '') != $meta->curr) {
        return false;
    }

    return true;
}

/**
 * Compare tp_event and canvas_event, returning all differences
 * Check for changes in title, location, start-date, end-date, staff and recording tag
 *
 * @param object $tp_event event from tp-ws
 * @param object $canvas_event event from canvas-ws
 * @param string $courseid Course id (e.g. INFO-1100). Required for title.
 * @return array An array of differences
 */
function tp_event_equals_canvas_event2(object $tp_event, object $canvas_event, string $courseid): array
{
    global $log;

    $diff = [];

    // If event is marked as deleted in canvas, pretend it's not there
    if ($canvas_event->workflow_state == 'deleted') {
        $diff['workflow'] = [0, 1];
    }

    $compare = tpToCanvasEvent($tp_event, $courseid, 0); // Missing canvas course id

    // Title
    if ($compare->title != $canvas_event->title) {
        $diff['title'] = [$compare->title, $canvas_event->title];
    }

    // Location
    if ($compare->location_name != $canvas_event->location_name) {
        $diff['location_name'] = [$compare->location_name, $canvas_event->location_name] ;
    }

    // Dates
    if (strtotime($compare->start_at) != strtotime($canvas_event->start_at)) {
        $diff['start_at'] = [$compare->start_at, $canvas_event->start_at];
    }
    if (strtotime($compare->end_at) != strtotime($canvas_event->end_at)) {
        $diff['end_at'] = [$compare->end_at, $canvas_event->end_at];
    }

    // Fetch recording, curriculum and staff from canvas_event
    $dom = new PHPHtmlParser\Dom;
    $dom->load($canvas_event->description);
    $meta = $dom->find('span#description-meta', 0);
    if (!$meta) {
        $log->warn("Missing meta from Canvas event", ['cevent' => $canvas_event]);
        $diff['meta'] = [1, 0];
    }
    $meta = json_decode($meta->text(true));

    // Staff array
    $staff_arr = array();
    if (isset($tp_event->staffs) && is_array($tp_event->staffs)) {
        $staff_arr = array_map(function ($staff) {
            return "{$staff->firstname} {$staff->lastname}";
        }, $tp_event->staffs);
    }
    if (isset($tp_event->{'xstaff-list'}) && is_array($tp_event->{'xstaff-list'})) {
        $staff_arr = array_merge($staff_arr, array_map(function ($staff) {
            return "{$staff->name} (ekstern) {$staff->url}";
        }, $tp_event->{'xstaff-list'}));
    }
    sort($staff_arr);
    sort($meta->staff);
    if ($staff_arr != $meta->staff) {
        $diff['staff'] = [$staff_arr, $meta->staff];
    }

    // Recording tag
    $recording = false;
    if (isset($tp_event->tags) && is_array($tp_event->tags)) {
        $tags = preg_grep('/Mediasite/', $tp_event->tags);
        if (count($tags) > 0) {
            $recording = true;
        }
    }
    if ($recording != $meta->recording) {
        $diff['recording'] = [$recording, $meta->recording];
    }

    // Curriculum
    if (md5($tp_event->curr ?? '') != $meta->curr) {
        $diff['curr'] = [md5($tp_event->curr ?? ''), $meta->curr];
    }

    return $diff;
}


/**
 * Create event in Canvas and database
 *
 * @param object $event The event definition (from tp)
 * @param object $db_course The course db object to add event to
 * @param string $courseid (KVI-1014)
 * @param int $canvas_course_id (12325)
 * @return bool Operation success flag
 * @todo Ensure result is returned properly
 */
function add_event_to_canvas(object $event, object $db_course, string $courseid, int $canvas_course_id): bool
{
    global $log, $canvasclient;

    $cevent = tpToCanvasEvent($event, $courseid, $canvas_course_id);

    if ($_SERVER['dryrun'] == 'on') {
        $log->debug("Skipped calendar post", array('payload' => $cevent));
        return true;
    }

    try {
        $response = $canvasclient->calendar_events_post($cevent);
    } catch (\RuntimeException $e) {
        $log->error("Event creation failed in Canvas.", [
            'event' => $event,
            'payload' => $cevent,
            'exception' => $e
        ]);
        return false;
    }

    /** @todo There used to be a test here for 201  */
    // Save to database if ok
    $db_event = new CanvasDbEvent();
    $db_event->canvas_id = $response->id;
    $db_event->canvas_course_id = $canvas_course_id;
    $db_event->save();
    $log->debug("Event created in Canvas", ['event' => $event]);
    return true;
}

/**
 * TP Event to Canvas event conversion
 *
 * @param object $tpevent
 * @param string $courseid KVI-1014
 * @param string $canvas_course_id id number in Canvas
 * @return object|nil
 */
function tpToCanvasEvent(object $tpevent, string $courseid, int $canvas_course_id): object
{

    global $canvasclient;

    $canvasevent = new \stdClass();

    // Context code
    $canvasevent->context_code = "course_{$canvas_course_id}";

    // Title
    $canvasevent->title = "{$courseid} {$tpevent->summary}";
    if (isset($tpevent->title) && $tpevent->title) {
        $canvasevent->title = "{$courseid} ({$tpevent->title}) {$tpevent->summary}";
    }
    // Canvas API won't accept titles over 255 _characters_ (not bytes)
    if (mb_strlen($canvasevent->title) > 253) {
        $canvasevent->title = mb_substr($canvasevent->title, 0, 253);
    }
    $canvasevent->title .= "\u{200B}\u{200B}";

    // Times
    $canvasevent->start_at = $tpevent->dtstart;
    $canvasevent->end_at = $tpevent->dtend;
    
    // Location
    $canvasevent->location_name = '';
    $map_url = '';
    if (isset($tpevent->room) && is_array($tpevent->room)) {
        $canvasevent->location_name = array_map(function ($room) {
            return "{$room->buildingid} {$room->roomid}";
        }, $tpevent->room);
        $canvasevent->location_name = implode(', ', $canvasevent->location_name);
        foreach ($tpevent->room as $room) {
            $room_name="{$room->buildingid} {$room->roomid}";
            $room_url="https://uit.no/mazemaproom?room_name=".urlencode($room_name)."&zoom=20";
            $map_url .= "<a href={$room_url}> {$room_name}</a><br>";
        }
    }

    // Staff array (used for meta array)
    $staff_arr = array();
    if (isset($tpevent->staffs) && is_array($tpevent->staffs)) {
        $staff_arr = array_map(function ($staff) {
            return "{$staff->firstname} {$staff->lastname}";
        }, $tpevent->staffs);
    }
    if (isset($tpevent->{'xstaff-list'}) && is_array($tpevent->{'xstaff-list'})) {
        $staff_arr = array_merge($staff_arr, array_map(function ($staff) {
            return "{$staff->name} (ekstern) {$staff->url}";
        }, $tpevent->{'xstaff-list'}));
    }

    // Staff string (used for description)
    $staff = array();
    if (isset($tpevent->staffs) && is_array($tpevent->staffs)) {
        $staff = array_map(function ($staffp) {
            return "{$staffp->firstname} {$staffp->lastname}";
        }, $tpevent->staffs);
    }
    if (isset($tpevent->{'xstaff-list'}) && is_array($tpevent->{'xstaff-list'})) {
        $staff = array_merge($staff_arr, array_map(function ($staffp) {
            if ($staffp->url != '') {
                return "<a href='{$staffp->url}'>{$staffp->name} (ekstern)</a>";
            }
            return "{$staffp->name} (ekstern) {$staffp->url}";
        }, $tpevent->{'xstaff-list'}));
    }
    $staff = implode("<br>", $staff);

    // Recording tag
    $recording = false;
    if (isset($tpevent->tags) && is_array($tpevent->tags)) {
        // Apparently any tag containing the word "Mediasite" means recording
        $tags = preg_grep('/Mediasite/', $tpevent->tags);
        if (count($tags) > 0) {
            $recording = true;
        }
    }

    // Meta object
    $description_meta = new \stdClass();
    $description_meta->recording = $recording;
    $description_meta->staff = $staff_arr;
    $description_meta->curr = md5($tpevent->curr ?? '');

    // Create description
    $canvasevent->description = erb_description(
        $recording,
        $map_url,
        $staff,
        ($tpevent->curr ?? ''),
        ($tpevent->editurl ?? ''),
        $description_meta
    );
    return $canvasevent;
}

/**
 * Description text renderer
 *
 * @param boolean $recording
 * @param string $map_url
 * @param string $staff
 * @param string $curr
 * @param string $editurl
 * @param array $description_meta
 * @return string
 */
function erb_description(
    bool $recording,
    string $map_url,
    string $staff,
    string $curr,
    string $editurl,
    object $description_meta
): string {
    $timenow = strftime("%d.%m.%Y %H:%M");
    $description_meta = json_encode($description_meta);
    $out = '';
    if ($recording) {
        $out .= <<<EOT
            <strong>Automatiserte opptak</strong><br>
            <img src="https://uit.no/ressurs/canvas/film.png"><br>
            <a href="https://uit.no/om/enhet/artikkel?p_document_id=578589&p_dimension_id=88225&men=28927">
            Mer informasjon</a><br>
            <br>
            EOT;
    }
    if ($map_url) {
        $out .= "<strong>Mazemap</strong><br>{$map_url}<br><br>\n";
    }
    if ($staff) {
        $out .= "<strong>Fagpersoner</strong><br>{$staff}<br><br>\n";
    }
    if ($curr) {
        $out .= "<strong>Pensum</strong><br>{$curr}<br><br>\n";
    }
    $out .= <<<EOT
        <a class="uit_instructoronly" href="{$editurl}">Detaljering</a><br>
        <br>
        <div style="color: #007bff;">
            <small>**************************************************</small><br>
            <small>Denne hendelsen er automatisk lagt til kalenderen.</small><br>
            <small>Den må <em>ikke</em> redigeres i Canvas.</small><br>
            <small>**************************************************</small><br>
        </div>
        <div style="color: #6c757d;">
            <small><small>Oppdatert {$timenow}</small></small>
        </div>
        <span id="description-meta" style="display:none">{$description_meta}</span>
        EOT;
    return $out;
}

/**
 * Delete single canvas event (db and canvas)
 *
 * @param object event database object of event
 * @return bool operation success
 * @todo implement error returns
 */
function delete_canvas_event(CanvasDbEvent $event): bool
{
    global $log, $canvasclient;

    if ($_SERVER['dryrun'] == 'on') {
        $log->debug("Skipped calendar delete", array('event' => $event));
        return true;
    }

    try {
        $response = $canvasclient->calendar_events_delete($event->canvas_id);
        $log->debug("Deletion", ['response' => $response]);
    } catch (GuzzleHttp\Exception\ClientException $e) {
        if ($e->getResponse()->getStatusCode() == 404) {
            // Event not found in Canvas, let's just forget about it
            $event->delete();
            $log->warning("Event missing in Canvas", ['event'=>$event]);
            return true;
        } elseif ($e->getResponse()->getStatusCode() == 401) {
            // unauthorized, let me count the ways...
            try {
                $response = $canvasclient->calendar_events_get($event->canvas_id);
            } catch (\RuntimeException $e) {
                // Can't read from canvas, abort operation
                $log->error("Unable to delete event in Canvas (info fetch)", ['event' => $event, 'e' => $e]);
                return false;
            }
            if ($response->workflow_state == 'deleted') {
                // Is the event deleted in canvas?
                $event->delete();
                $log->warning("Event marked as deleted in Canvas", ['event' => $event]);
                return true;
            }
            $log->error("Unable to delete event in Canvas", ['event' => $event, 'e' => $e]);
            return false;
        }
        $log->error("Unable to delete event in Canvas", [
            'event' => $event,
            'error' => $e->getResponse()->getStatusCode()
        ]);
        return false;
    } catch (\RuntimeException $e) {
        $log->error("Unable to delete event in Canvas", [
            'event' => $event,
            'error' => $e->getResponse()->getStatusCode()
        ]);
        return false;
    }
    $event->delete();
    $log->info("Event deleted in Canvas", ['event' => $event]);
    return true;
}

/**
 * Delete all Canvas events for a course (db and canvas)
 *
 * @param object $course database course object
 * @return void
 * @todo implement error returns - how would that look? would we care?
 */
function delete_canvas_events(CanvasDbCourse $course)
{
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
 * @return void
 */
function add_timetable_to_canvas(array $courses, array $tp_activities, string $courseid)
{
    global $log;

    /** No activities, let's bail */
    if (!$tp_activities) {
        return;
    }

    foreach ($courses as $course) {
        // Find matching timetable
        $actid = explode('_', $course->sis_course_id);
        $actid = end($actid);
        $log->debug("Matching course", [
            'sis id' => $course->sis_course_id,
            'sis import id' => $course->sis_import_id,
            'integration id' => $course->integration_id,
            'actid' => $actid
        ]);
        $log->debug("Pre filter timetable", [array_column($tp_activities, 'actid', 'id')]);
        $act_timetable = array_filter($tp_activities, function ($tp_act) use ($actid) {
            return ($tp_act->actid == $actid);
        });
        $log->debug("Post filter timetable", [array_column($act_timetable, 'actid', 'id')]);
        add_timetable_to_one_canvas_course($course, $act_timetable, $courseid);
    }
}

/**
 * Add a tp timetable to one canvas course
 *
 * @param object $canvas_course from canvas json
 * @param array $timetable from tp json
 * @param string $courseid (e.g INF-1100)
 * @return void
 * @todo waaay too many error conditions to return
 */
function add_timetable_to_one_canvas_course(object $canvas_course, array $timetable, string $courseid)
{
    global $log, $canvasclient;

    $db_course = CanvasDbCourse::find_or_create((int) $canvas_course->id);
    $db_course->name = $canvas_course->name;
    $db_course->course_code = $canvas_course->course_code;
    $db_course->sis_course_id = $canvas_course->sis_course_id;
    $db_course->save();

    // Empty tp-timetable, flush everything in Canvas
    if (!$timetable) {
        delete_canvas_events($db_course);
        return;
    }

    // put tp-events in array (unnesting)
    $tp_events = array();
    foreach ($timetable as $t) {
        foreach ($t->eventsequences as $eventsequence) {
            foreach ($eventsequence->events as $event) {
                $tp_events[] = $event;
            }
        }
    }

    // fetch canvas events found in db
    foreach ($db_course->canvas_events as $canvas_event_db) {
        try {
            // Get the corresponding canvas object
            $canvas_event_ws = $canvasclient->calendar_events_get($canvas_event_db->canvas_id);
        } catch (\RuntimeException $e) {
            // Could not read from Canvas, skip to next
            // @todo This is probably not the correct way to handle it, should
            // the exception bubble up? What happens on a 404?
            $log->error("Could not read calendar from canvas", ['e' => $e, 'event' => $canvas_event_db]);
            break;
        }
        $found_tp_event = false;

        // Look for match between canvas and tp
        foreach ($tp_events as $i => $tp_event) {
            if (tp_event_equals_canvas_event($tp_event, $canvas_event_ws, $courseid)) {
                // No need to update, remove tp_event from array of events
                unset($tp_events[$i]);
                $found_tp_event = true;
                $log->debug(
                    "Event match in TP and Canvas - no update needed",
                    ['db_course' => $db_course,'canvas_event_db' => $canvas_event_db]
                );
                break;
            }
        }

        if (!$found_tp_event) {
            // Nothing matched in tp, this event has been deleted from tp
            delete_canvas_event($canvas_event_db);
        }
    }

    // Add remaining tp-events in canvas
    foreach ($tp_events as $event) {
        add_event_to_canvas($event, $db_course, $courseid, $canvas_course->id);
    }
}

function cevent2str(object $cevent)
{
    $date = strtotime($cevent->start_at);
    $date = date("dmy H:i", $date);
    return "{$cevent->id}:{$cevent->title} {$date}";
}

function tpevent2str(object $tpevent)
{
    $date = strtotime($tpevent->dtstart);
    $date = date("dmy H:i", $date);
    return "{$tpevent->eventid}:{$tpevent->summary} {$date}";
}

/**
 * Remove local courses that have been removed from Canvas
 *
 * @param array $canvas_courses
 * @return void
 */
function remove_local_courses_missing_from_canvas(array $canvas_courses)
{
    foreach ($canvas_courses as $course_id) {
        $local_course = CanvasDbCourse::find($course_id);
        $local_course->remove_all_canvas_events();
        $local_course->delete();
    }
}

/**
 * Generate a sis course id string
 * @todo this isn't good enough, termnr should be versionnr, but that isn't available from tp.
 *
 * @param string $courseid
 * @param string $semesterid
 * @param int $termnr
 * @return string SIS course id in the form INF-1100_2_2017_HØST
 */
function make_sis_course_id(string $courseid, string $semesterid, int $termnr): string
{
    $semesteryear = substr($semesterid, 0, 2);
    $sis_course_id = "{$courseid}_{$termnr}_20{$semesteryear}_VÅR";
    if (strtoupper(substr($semesterid, -1)) == "H") {
        $sis_course_id = "{$courseid}_{$termnr}_20{$semesteryear}_HØST";
    }
    return $sis_course_id;
}

/**
 * Convert TP semester id and term number to Canvas SIS format
 *
 * @param string $semesterid e.g "18h"
 * @param int $termnr e.g "3"
 * @return string Canvas SIS term id
 */
function make_sis_semester(string $semesterid, int $termnr): string
{
    // First convert to first term of course, since that is what Canvas uses
    list ($semesterid, $termnr) = firstSem($semesterid, $termnr);

    $semesteryear = substr($semesterid, 0, 2);
    $sis_semester = "20{$semesteryear}_VÅR_{$termnr}";
    if (strtoupper(substr($semesterid, -1)) == "H") {
        $sis_semester = "20{$semesteryear}_HØST_{$termnr}";
    }
    return $sis_semester;
}

/**
 * Search string for substring match on any of an array of strings
 *
 * @param string $haystack The string to search withing
 * @param array $needles Array of strings to search for
 * @return bool True for match found, false for no matches
 */
function haystack_needles(string $haystack, array $needles): bool
{
    foreach ($needles as $needle) {
        if (stripos($haystack, $needle) !== false) {
            return true;
        }
    }
    return false;
}

/** Semnr to semstring
 * This converts decimal numeric representation of a semester to string representation
 *
 * @param float $semnr Numerical representation of a semester (e.g 18.5)
 * @return string String representation of a semester (e.g "18h")
*/
function semnr_to_string(float $semnr): string
{
    $sem = 'h';
    if (($semnr - (int) $semnr) == 0) { // .0 is vår
        $sem = 'v';
    }
    $semyear = intval($semnr);
    return sprintf("%02d%s", $semyear, $sem);
}

/** semstring to semnr
 * This converts string representation of a semester to a decimal numeric representation
 *
 * @param string $semstring String representation of a semester (e.g "18h")
 * @return float Numerical representation of a semester (e.g "18.5")
*/
function string_to_semnr(string $semstring): float
{
    /** @todo Really need some kind of validation here - this can fail in so many ways */
    $semarray = preg_split('/([h|v])/i', $semstring, -1, 2);
    $semyear = (float) $semarray[0];
    if (strtolower($semarray[1]) == "h") {
        $semyear += 0.5;
    }
    return $semyear;
}

/**
 * Fetch canvas courses from webservice, removing wrong semester and wrong courseid
 *
 * @param string $courseid 'INF-1100'
 * @param string $semesterid '18v'
 * @param int $termnr '3'
 * @param bool $exact - Should everything that isn't a match be removed
 * @return array a list of courses that match the chosen query
 *
 * @todo Really needs better error handling.
 */
function fetch_and_clean_canvas_courses(
    string $courseid,
    string $semesterid,
    int $termnr
): array {
    global $log, $canvasclient;
    // Fetch Canvas courses
    /** @todo we need better error checking here */
    try {
        $canvas_courses = $canvasclient->accounts_courses(1, ['search_term' => $courseid]);
    } catch (\RuntimeException $e) {
        $log->error("Unable to read paginated course list", [$e]);
        return array();
    }
    // Remove all with wrong semester and wrong courseid
    $sis_semester = make_sis_semester($semesterid, $termnr);
    /* Keep courses that fills all criterias:
        1. Has a sis_course_id (if not, it's not from FS)
        2. sis_course_id contains our course id as an element
        3. sis_course_id contains our semester
    */
    $canvas_courses = array_filter($canvas_courses, function (object $course) use ($courseid, $sis_semester) {
        if (!isset($course->sis_course_id)) {
            return false;
        }
        if (is_null($course->sis_course_id)) {
            return false;
        }
        if (stripos($course->sis_course_id, "_{$courseid}_") === false) {
            return false;
        }
        if (stripos($course->sis_course_id, $sis_semester) === false) {
            return false;
        }
        return true;
    });
    return $canvas_courses;
}

/**
 * Process a received queue message
 *
 * @param PhpAmqpLib\Message\AMQPMessage $msg Message received.
 * @return void
 */
function queue_process(PhpAmqpLib\Message\AMQPMessage $msg)
{
    global $log, $changelist;

    $course = json_decode($msg->body, true);

    if (strpos($course['id'], 'BOOKING') !== false || strpos($course['id'], 'EKSAMEN') !== false) {
        // Ignore BOOKING and EKSAMEN messages
        $log->debug("Skipping because of type", ['message' => $msg]);
        $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
        return;
    }

    if (!verifySem($course['semesterid'])) {
        // Ignore future semesters
        $log->debug("Skipping because of future semester", ['message' => $msg]);
        $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
        return;
    }

    if ($changelist->check($course['id'], $course['lastchanged'])) {
        // Ignore changes that happened before our last update
        $log->debug("Skipping because course already updated after time of change", ['message' => $msg]);
        $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
        return;
    }

    $changetime = date('c'); // Timestamp before when processing starts
    $log->info("Message received from RabbitMQ", ['message' => $msg]);

    /** @todo error handling */
    $result = cmd_course($course['id'], $course['semesterid'], $course['terminnr']);

    if ($result) {
        // If processing succeeded
        $changelist->set($course['id'], $changetime);
        if ($_SERVER['dryrun'] != 'on') {
            // If dryrun isn't on
            $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
        }
    }
}

function fetchCourses(CanvasClient $canvas, string $search)
{
    $courses = $canvas->accounts_courses(1, [
        'with_enrollments' => true,
        'published' => true,
        'completed' => false,
        'blueprint' => false,
        'search_term' => $search,
        'include[]' => 'term',
        'starts_before' => date(DATE_ISO8601),
        'ends_after' => date(DATE_ISO8601)
    ]);
    $courses = array_filter($courses, function (object $course) {
        if (empty($course->sis_course_id)) {
            return false;
        }
        if (empty($course->term->sis_term_id)) {
            return false;
        }
        return true;
    });
    // Remap with id as key, to make it easier to detect courses across prod and test
    $coursesids = array_map(function (object $course) {
        return $course->id;
    }, $courses);
    $courses = array_combine($coursesids, $courses);
    return $courses;
}

/**
 * Fetch the entire (relevant) schedule for a course from TP
 *
 * @param string $courseid
 * @param string $semesterid
 * @param integer $termnr
 * @return object The TP object of first semester with all consecutive merged in
 */
function fetchTPSchedule(string $courseid, string $semesterid, int $termnr): ?object
{
    global $tpclient, $log;

    $schedule = null;

    list ($firstsem, $firstterm) = firstSem($semesterid, $termnr);
    list ($lastsem, $lastterm) = lastSem($semesterid, $termnr);

    $thissemnr = string_to_semnr($firstsem);

    for ($term = $firstterm; $term <= $lastterm; $term++) {
        try {
            $timetable = $tpclient->schedule(semnr_to_string($thissemnr), $courseid, $term);
        } catch (\RuntimeException $e) {
            $log->critical("Could not get timetable from TP", ['courseid' => $courseid, 'e' => $e]);
            return null;
        }
        if (is_null($schedule)) {
            // First timetable, grab as is
            $schedule = $timetable;
            if (is_null($schedule->data)) {
                $schedule->data = [];
            }
            $thissemnr += 0.5;
            continue;
        }
        // Consecutive timetables, merge activities
        if (!is_null($timetable->data)) {
            $timetable_categories = \get_object_vars($timetable->data);
            foreach ($timetable_categories as $key => $value) {
                if (!isset($schedule->data->{$key})) {
                    $schedule->data->{$key} = [];
                }
                $schedule->data->{$key} = array_merge($schedule->data->{$key}, $value);
            }
        }
        $thissemnr += 0.5;
    }
    return $schedule;
}

/**
 * Find first semester for a course
 *
 * @param string $semesterid
 * @param integer $termnr
 * @return array [$semesterid, $termnr]
 */
function firstSem(string $semesterid, int $termnr): array
{
    if ($termnr == 1) {
        return [$semesterid,1];
    }

    $semnumeric = string_to_semnr($semesterid);
    $semnumeric = $semnumeric - (0.5 * ($termnr - 1));
    return [semnr_to_string($semnumeric), 1];
}

/**
 * Find last semester for a course
 * This is roughly "the last semester we care about" - we don't know the actual last semester
 *
 * @param string $semesterid
 * @param integer $termnr
 * @return array [$semesterid, $termn]
 */
function lastSem(string $semesterid, int $termnr): array
{
    $maxsem = string_to_semnr($_SERVER['maxsem']);
    $thissem = string_to_semnr($semesterid);
    if ($thissem >= $maxsem) {
        // This is the last we know of
        return [$semesterid, $termnr];
    }
    // We go up to maxsem
    $termsmore = ($maxsem - $thissem) * 2;
    return [semnr_to_string($maxsem), $termnr + $termsmore];
}

/**
 * Verify if a semester is one we care about
 *
 * @param string $semesterid
 * @return bool
 */
function verifySem(string $semesterid): bool
{
    $maxsem = string_to_semnr($_SERVER['maxsem']);
    $thissem = string_to_semnr($semesterid);
    if ($thissem > $maxsem) {
        return false;
    }
    return true;
}

#endregion utilityfunctions
