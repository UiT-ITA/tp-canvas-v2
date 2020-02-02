<?php
/**
 * Main application file.
 */

namespace TpCanvas;

require_once "global.php";

use PHPHtmlParser;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use GuzzleHttp;

$log->info("Starting run");

$canvasHandlerStack = GuzzleHttp\HandlerStack::create();
$canvasHandlerStack->push(GuzzleHttp\Middleware::retry(retryDecider(), retryDelay()));
$canvasclient = new GuzzleHttp\Client([
    'base_uri' => "{$_SERVER['canvas_url']}api/v1/",
    'headers' => [
        'Authorization' => "Bearer {$_SERVER['canvas_key']}"
    ],
    'debug' => ($_SERVER['debug'] == "on" ? true : false),
    'handler' => $canvasHandlerStack,
    /** @todo fix exception support */
    'http_errors' => false // We are not exception compliant :-/
]);

$tpHandlerStack = GuzzleHttp\HandlerStack::create();
$tpHandlerStack->push(GuzzleHttp\Middleware::retry(retryDecider(), retryDelay()));
$tpclient = new GuzzleHttp\Client([
    'base_uri' => "{$_SERVER['tp_url']}ws/",
    'headers' => [
        'X-Gravitee-Api-Key' => $_SERVER['tp_key']
    ],
    'debug' => ($_SERVER['debug'] == "on" ? true : false),
    'handler' => $tpHandlerStack,
    /** @todo fix exception support */
    'http_errors' => false // We are not exception compliant :-/
]);

$pdoclient = new \PDO($_SERVER['db_dsn'], $_SERVER['db_user'], $_SERVER['db_password']);

if (!isset($argv[1])) {
    $argv[1] = '';
}

switch ($argv[1]) {
    case 'semester':
        full_sync($argv[2]);
        break;
    case 'course':
        update_one_tp_course_in_canvas($argv[2], $argv[3], $argv[4]);
        break;
    case 'removecourse':
        remove_one_tp_course_from_canvas($argv[2], $argv[3], $argv[4]);
        break;
    case 'mq':
        queue_subscriber();
        break;
    case 'canvasdiff':
        check_canvas_structure_change($argv[2]);
        break;
    default:
        echo "Command-line utility to sync timetables from TP to Canvas.\n";
        echo "Usage: {$argv[0]} [command] [options]\n";
        echo "  Add full semester: semester 18h\n";
        echo "  Add course: course MED-3601 18h 1\n";
        echo "  Remove course from Canvas: removecourse MED-3601 18h 1\n";
        echo "  Process changes from AMQP: mq\n";
        echo "  Check for Canvas change: canvasdiff 18h\n";
        break;
}
exit;

function retryDecider()
{
    return function (
        $retries,
        GuzzleHttp\Psr7\Request $request,
        GuzzleHttp\Psr7\Response $response = null,
        GuzzleHttp\Exception\ConnectException $exception = null
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
 *
 * @property-read array canvas_events An array of all CanvasEvent objects belonging to this course
 */
class CanvasCourse
{
    public ?int $id;
    public ?int $canvas_id;
    public ?string $name;
    public ?string $course_code;
    public ?string $sis_course_id;

    private ?object $pdoclient;

    /**
     * CanvasCourse constructor
     */
    public function __construct()
    {
        global $pdoclient;
        $this->pdoclient = $pdoclient;
    }

    /**
     * Find single course by canvas id or create a blank course object
     *
     * @param int $canvas_id Canvas id to search for
     * @return CanvasCourse course object, either with values (if found) or completely blank (if not found)
     */
    public static function find_or_create(int $canvas_id): CanvasCourse
    {
        global $pdoclient;
        $stmt = $pdoclient->prepare("SELECT * FROM canvas_courses WHERE canvas_id = ?");
        $stmt->execute(array($canvas_id));
        $result = $stmt->fetchObject('TpCanvas\\CanvasCourse');
        if ($result === false) {
            return new CanvasCourse;
        }
        return $result;
    }

    /**
     * Find single course by sis course id
     *
     * @param string $sis_course_id the sis course id to search with
     * @return CanvasCourse|null found course or null if none found
     */
    public static function find(string $sis_course_id): ?CanvasCourse
    {
        global $pdoclient;
        $stmt = $pdoclient->prepare("SELECT * FROM canvas_courses WHERE sis_course_id = ?");
        $stmt->execute(array($sis_course_id));
        $result = $stmt->fetchObject('TpCanvas\\CanvasCourse');
        if ($result === false) {
            return null;
        }
        return $result;
    }

    /**
     * Find all courses matching a sis_course_id wildcard search
     *
     * @param string $like the condition to search with, including % chars
     * @return array Array of CanvasCourse objects
     */
    public static function findBySisLike(string $like): array
    {
        global $pdoclient;
        $stmt = $pdoclient->prepare("SELECT * FROM canvas_courses WHERE sis_course_id like ?");
        $stmt->execute(array($like));
        $result = array();
        while ($course = $stmt->fetchObject('TpCanvas\\CanvasCourse')) {
            $result[] = $course;
        }
        return $result;
    }

    /**
     * Delete course
     *
     * @return boolean Was the delete successful
     */
    public function delete(): bool
    {
        if ($_SERVER['dryrun'] == 'on') {
            return true;
        }
        $this->pdoclient->prepare("DELETE FROM canvas_courses WHERE id = ?");
        return $this->pdoclient->execute(array($this->id));
    }

    /**
     * Save course to database
     *
     * @return boolean Was the save successful
     */
    public function save(): bool
    {
        if ($_SERVER['dryrun'] == 'on') {
            return true;
        }
        if ($this->id) {
            // Existing object
            $stmt = $this->pdoclient->prepare(
                "UPDATE canvas_courses SET
                canvas_id = :canvasid,
                name = :name,
                course_code = :coursecode,
                sis_course_id = :siscourseid
                WHERE id = :id"
            );
            $values = [
                ':canvasid' => $this->canvas_id,
                ':name' => $this->name,
                ':coursecode' => $this->course_code,
                ':siscourseid' => $this->sis_course_id,
                ':id' => $this->id
            ];
            return $stmt->execute($values);
        }
        // New object
        $stmt = $this->pdoclient->prepare(
            "INSERT INTO canvas_courses(
            canvas_id, name, course_code, sis_course_id) VALUES (
            :canvasid,
            :name,
            :coursecode,
            :siscourseid)"
        );
        $values = [
            ':canvasid' => $this->canvas_id,
            ':name' => $this->name,
            ':coursecode' => $this->course_code,
            ':siscourseid' => $this->sis_course_id
        ];
        return $stmt->execute($values);
    }

    /**
     * Remove all canvas events from database that belongs to this course
     *
     * @return void
     */
    public function remove_all_canvas_events()
    {
        foreach ($this->canvas_events as $event) {
            $event->delete();
        }
    }

    /**
     * Magic method to read canvas_events array
     *
     * @param string $name property name
     * @return mixed
     */
    public function __get(string $name)
    {
        if ($name == 'canvas_events') {
            if (isset($this->id)) {
                return CanvasEvent::findByCanvasCourseId($this->id);
            }
            return array();
        }
        return null;
    }
}

/**
 * Activerecord emulation wrapper class
 */
class CanvasEvent
{
    public int $id;
    public int $canvas_course_id;
    public int $canvas_id;

    private $pdoclient;

    /**
     * CanvasEvent constructor
     */
    public function __construct()
    {
        global $pdoclient;
        $this->pdoclient = $pdoclient;
    }

    /**
     * Find all events linked to a given Canvas course id
     *
     * @param integer $canvascourseid Canvas course id to search for
     * @return array Array of CanvasEvent objects
     */
    public static function findByCanvasCourseId(int $canvascourseid): array
    {
        global $pdoclient;
        $stmt = $pdoclient->prepare("SELECT * FROM canvas_events WHERE canvas_course_id = ?");
        $stmt->execute(array($like));
        $result = array();
        while ($event = $stmt->fetchObject('TpCanvas\\CanvasEvent')) {
            $result[] = $event;
        }
        return $result;
    }

    /**
     * Delete this event from the database
     *
     * @return bool Did the delete complete successfully
     */
    public function delete(): bool
    {
        if ($_SERVER['dryrun'] == 'on') {
            return true;
        }
        $this->pdoclient->prepare("DELETE FROM canvas_events WHERE id = ?");
        return $this->pdoclient->execute(array($this->id));
    }

    /**
     * Save this event to the database
     *
     * @return boolean Did the save complete successfully
     */
    public function save(): bool
    {
        if ($_SERVER['dryrun'] == 'on') {
            return true;
        }
        if ($this->id) {
            // Existing object
            $stmt = $this->pdoclient->prepare(
                "UPDATE canvas_events SET
                canvas_course_id = :canvascourseid,
                canvas_id = :canvasid,
                WHERE id = :id"
            );
            $values = [
                ':canvascourseid' => $this->canvas_course_id,
                ':canvasid' => $this->canvas_id,
                ':id' => $this->id
            ];
            return $stmt->execute($values);
        }
        // New object
        $stmt = $this->pdoclient->prepare(
            "INSERT INTO canvas_events(
            canvas_course_id, canvas_id) VALUES (
            :canvascourseid,
            :canvasid)"
        );
        $values = [
            ':canvascourseid' => $this->canvas_course_id,
            ':canvasid' => $this->canvas_id
        ];
        return $stmt->execute($values);
    }
}

/**
 * Compare tp_event and canvas_event
 * Check for changes in title, location, start-date, end-date, staff and recording tag
 *
 * @param array $tp_event array from tp-ws
 * @param array $canvas_event array from canvas-ws
 * @param string $courseid Course id (e.g. INFO-1100). Required for title.
 * @return bool wether the events was "equal"
 */
function tp_event_equals_canvas_event(array $tp_event, array $canvas_event, string $courseid): bool
{
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
    $dom = new PHPHtmlParser\Dom;
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
 * @return bool Operation success flag
 * @todo Ensure result is returned properly
 */
function add_event_to_canvas(array $event, object $db_course, string $courseid, string $canvas_course_id): bool
{
    global $log, $canvasclient;

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
    $staff = array();
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

    $curr = ( isset($event['curr']) ? $event['curr'] : '');
    $editurl = $event['editurl'] ?? '';
    $description_meta = array(
        'recording' => $recording,
        'staff' => $staff_arr,
        'curr' => md5($curr)
    );

    // Send to Canvas
    $contents = [
        'calendar_event' => [
            'context_code' => "course_{$canvas_course_id}",
            'title' => $title,
            'description' => erb_description($recording, $map_url, $staff, $curr, $editurl, $description_meta),
            'start_at' => $event['dtstart'],
            'end_at' => $event['dtend'],
            'location_name' => $location
        ]
    ];
    if ($_SERVER['dryrun'] == 'on') {
        $log->debug("Skipped calendar post", array('payload' => $contents));
        return true;
    }
    
    $response = $canvasclient->post('calendar_events.json', [
        'json' => $contents
    ]);

    // Save to database if ok
    if ($response->getStatusCode() == 201) {
        $responsedata = json_decode($response->getBody(), true);
        $db_event = new CanvasEvent();
        $db_event->canvas_id = $responsedata['id'];
        $db_event->save();
        $log->info("Event created in Canvas", ['event' => $event, 'created' => $responsedata]);
        return true;
    }
    $log->warn("Event creation failed in Canvas.", ['event' => $event, 'response' => $response]);
    return false;
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
    array $description_meta
): string {
    $timenow = strftime("%d.%m.%Y %H:%M");
    $description_meta = json_encode($description_meta);
    $out = '';
    if ($recording) {
        $out .= <<<EOT
<strong>Automatiserte opptak</strong><br>
<img src="https://uit.no/ressurs/canvas/film.png"><br>
<a href="https://uit.no/om/enhet/artikkel?p_document_id=578589&p_dimension_id=88225&men=28927">Mer informasjon</a><br>
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
function delete_canvas_event(CanvasEvent $event): bool
{
    global $log, $canvasclient;

    if ($_SERVER['dryrun'] == 'on') {
        $log->debug("Skipped calendar delete", array('event' => $event));
        return true;
    }

    $response = $canvasclient->delete("calendar_events/{$event->canvas_id}.json");
    if ($response->getStatusCode() == 200) { // OK
        $event->delete();
        $log->info("Event deleted in Canvas", ['event' => $event]);
    } elseif ($response->getStatusCode() == 404) { // NOT FOUND
        $event->delete();
        $log->warning("Event missing in Canvas", ['event'=>$event]);
    } elseif ($response->getStatusCode() == 401) { // UNAUTHORIZED
        // Is the event deleted in canvas?
        $response = $canvasclient->get("calendar_events/{$event->canvas_id}.json");
        $responsedata = json_decode($response->getBody(), true);
        if ($responsedata['workflow_state'] == 'deleted') {
            $event->delete();
            $log->warning("Event marked as deleted in Canvas", ['event'=>$event]);
        } else {
            $log->error("Unable to delete event in Canvas", ['event'=>$event]);
            return false;
        }
    } else {
        $log->error("Unable to delete event in Canvas", ['event'=>$event, 'error'=>$response->getStatusCode()]);
        return false;
    }
    return true;
}

/**
 * Delete all Canvas events for a course (db and canvas)
 *
 * @param object $course database course object
 * @return void
 * @todo implement error returns - how would that look? would we care?
 */
function delete_canvas_events(CanvasCourse $course)
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
 * @return void
 * @todo waaay too many error conditions to return
 */
function add_timetable_to_one_canvas_course(array $canvas_course, array $timetable, string $courseid)
{
    global $log, $canvasclient;

    $db_course = CanvasCourse::find_or_create((int) $canvas_course['id']);
    $db_course->name = $canvas_course['name'];
    $db_course->course_code = $canvas_course['course_code'];
    $db_course->sis_course_id = $canvas_course['sis_course_id'];
    $db_course->save();

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
    foreach ($db_course->canvas_events as $canvas_event_db) {
        /** @todo error checking! */
        $response = $canvasclient->get("calendar_events/{$canvas_event_db->canvas_id}.json");
        $canvas_event_ws = json_decode($response->getBody(), true);
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
 * @return void
 */
function remove_local_courses_missing_from_canvas(array $canvas_courses)
{
    foreach ($canvas_courses as $course_id) {
        $local_course = CanvasCourse::find($course_id);
        $local_course->remove_all_canvas_events();
        $local_course->delete();
    }
}

/**
 * Check for structural changes in canvas courses.
 * Only called explicitly from command line - called in cronjob
 *
 * @param string $semester Semester string "YY[h|v]" e.g "18v"
 * @return void
 */
function check_canvas_structure_change($semester)
{
    global $log, $tpclient;

    // Fetch all active courses from TP
    $tp_courses = $tpclient->get("course", ['query' => ['id' => $_SERVER['tp_institution'], 'sem' => $semester, 'times' => 1]]);
    if ($tp_courses->getStatusCode() != 200) {
        $log->critical("Could not get course list from TP", array($semester));
        return;
    }
    $tp_courses = json_decode($tp_courses->getBody(), true);

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
            update_one_tp_course_in_canvas($tp_course['id'], $semester, $tp_course['terminnr']);
            $log->warning('Course changed in canvas and need to update', ['tp_course' => $tp_course, 'semester' => $semester]);
        }
    }
}

/**
 * Update entire semester in Canvas
 *
 * @param string $semester "YY[h|v]" e.g "18v"
 * @return void
 */
function full_sync(string $semester)
{
    global $log, $tpclient;

    $log->info("Starting full sync", ['semester' => $semester]);

    // Fetch all active courses from TP
    $tp_courses = $tpclient->get("course", ['query' => ['id' => $_SERVER['tp_institution'], 'sem' => $semester, 'times' => 1]]);
    if ($tp_courses->getStatusCode() != 200) {
        $log->critical("Could not get course list from TP", array($semester));
        return;
    }
    $tp_courses = json_decode($tp_courses->getBody(), true);

    foreach ($tp_courses['data'] as $tp_course) {
        // Stupid thread argument wrapping start
        $t_id = $tp_course['id'];
        $t_semesterid = $semester;
        $t_terminnr = $tp_course['terminnr'];
        // Stupid thread argument wrapping end
        $log->info("Updating one course", ['course' => $tp_course]);
        update_one_tp_course_in_canvas($t_id, $t_semesterid, $t_terminnr);
        /** @todo error handling here? */
    }
}

/**
 * Remove events for one tp course from canvas
 * Called explicitly from commandline
 *
 * @param string $courseid
 * @param string $semesterid
 * @param string $termnr
 * @return void
 */
function remove_one_tp_course_from_canvas(string $courseid, string $semesterid, string $termnr)
{
    $sis_semester = make_sis_semester($semesterid, $termnr);

    /** @todo verify this like condition */
    $courses = CanvasCourse::findBySisLike("%{$courseid}\\_%\\_{$sis_semester}%");
    foreach ($courses as $course) {
        delete_canvas_events($course);
    }
}

/**
 * Generate a sis course id string
 * @todo this isn't good enough, termnr should be versionnr, but that isn't available from tp.
 *
 * @param string $courseid
 * @param string $semesterid
 * @param string $termnr
 * @return string SIS course id in the form INF-1100_2_2017_HØST
 */
function make_sis_course_id(string $courseid, string $semesterid, string $termnr): string
{
    $semesteryear = substr($semesterid, 0, 2);
    $sis_course_id = '';
    if (strtoupper(substr($semesterid, -1)) == "H") {
        $sis_course_id = "{$courseid}_{$termnr}_20{$semesteryear}_HØST";
    } else {
        $sis_course_id = "{$courseid}_{$termnr}_20{$semesteryear}_VÅR";
    }
    return $sis_course_id;
}

/**
 * Convert TP semester id and term number to Canvas SIS format
 *
 * @param string $semesterid e.g "18h"
 * @param string $termnr e.g "3"
 * @return string Canvas SIS term id
 */
function make_sis_semester(string $semesterid, string $termnr): string
{
    $semesteryear = substr($semesterid, 0, 2);
    $sis_semester = '';
    if (strtoupper(substr($semesterid, -1)) == "H") {
        $sis_semester = "20{$semesteryear}_HØST_{$termnr}";
    } else {
        $sis_semester = "20{$semesteryear}_VÅR_{$termnr}";
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
 * This converts decimal numeric representation of a semester to a string.
 *
 * @param float $semnr Numerical representation of a semester (e.g 18.5)
 * @return string String representation of a semester (e.g "18h")
*/
function semnr_to_string(float $semnr): string
{
    if ($semnr % 1 == 0) {
        $sem = 'v';
    } else {
        $sem = 'h';
    }
    $semyear = intval($semnr);
    return sprintf("%02d%s", $semyear, $sem);
}

/** semstring to semnr
 * This converts decimal numeric representation of a semester to a string
 *
 * @param string $semstring String representation of a semester (e.g "18h")
 * @return float Numerical representation of a semester (e.g "18.5")
*/
function string_to_semnr(string $semstring): float
{
    /** @todo Really need some kind of validation here - this can fail in so many ways */
    $semarray = preg_split('/([h|v])/i', $semstring, null, 2);
    $semyear = (float) $semarray[0];
    if (strtolower($semarray[1]) == "h") {
        $semyear += 0.5;
    }
    return $semyear;
}

/**
 * Fetch canvas courses crom webservice, removing wrong semester and wrong courseid
 *
 * @param string $courseid 'INF-1100'
 * @param string $semesterid '18v'
 * @param string $termnr '3'
 * @param bool $exact - Should everything that isn't a match be removed
 * @return array a list of courses that match the chosen query
 *
 * @todo Really needs better error handling.
 */
function fetch_and_clean_canvas_courses(
    string $courseid,
    string $semesterid,
    string $termnr,
    bool $exact = true
): array {
    global $log, $canvasclient;
    // Fetch Canvas courses
    /** @todo we need better error checking here */
    $response = $canvasclient->get("accounts/1/courses", ['query' => ['search_term' => $courseid, 'per_page' => 100]]);
    $canvas_courses = json_decode((string) $response->getBody(), true);
    $nextpage = getPSR7NextPage($response);
    // Loop through all pages
    while ($nextpage) {
        $response = $canvasclient->get($nextpage);
        array_merge($canvas_courses, json_decode((string) $response->getBody(), true));
        $nextpage = getPSR7NextPage($response);
    }

    if ($exact) {
        // Remove all with wrong semester and wrong courseid
        $sis_semester = make_sis_semester($semesterid, $termnr);
        /* Keep courses that fills all criterias:
            1. Has a sis_course_id (if not, it's not from FS)
            2. sis_course_id contains our course id as an element
            3. sis_course_id contains our semester
        */
        array_filter($canvas_courses, function (array $course) use ($courseid, $sis_semester) {
            if (!isset($course['sis_course_id'])) {
                return false;
            }
            if (stripos($course['sis_course_id'], "_{$courseid}_") === false) {
                return false;
            }
            if (stripos($course['sis_course_id'], $sis_semester) === false) {
                return false;
            }
        });
    } else {
        // Create array of all valid sis semester combos for this course
        $combos = [];
        $semnr = string_to_semnr($semesterid);
        $csemnr = $semnr;
        $cterm = intval($termnr);
        while ($cterm > 0) {
            $combos[] = make_sis_semester(semnr_to_string($csemnr), $cterm);
            $csemnr -= 0.5;
            $cterm -= 1;
        }

        // Remove wrong course ids
        array_filter($canvas_courses, function (array $course) use ($courseid) {
            if (!isset($course['sis_course_id'])) {
                return false;
            }
            if (stripos($course['sis_course_id'], "_{$courseid}_") === false) {
                return false;
            }
        });
        // Remove courses that does not matchy any of our semester combos
        array_filter($canvas_courses, function (array $course) use ($combos) {
            return haystack_needles($course['sis_course_id'], $combos);
        });
    }
    return $canvas_courses;
}

/**
 * Find next page of a paginated canvas response
 *
 * @param GuzzleHttp\Psr7\Response $response Response from Canvas API
 * @return string The uri for next page, empty string otherwise.
 * @todo Needs better error handling
 */
function getPSR7NextPage(GuzzleHttp\Psr7\Response $response): string
{
    $linkheader= $response->getHeader('Link')[0]; // Get link header - needs error handling
    $nextpage = array();
    // Parse out all links
    preg_match_all('/\<(.+)\>; rel=\"(\w+)\"/iU', $linkheader, $nextpage, PREG_SET_ORDER);
    // Reduce to only next link
    $nextpage = array_filter($nextpage, function (array $entry) {
        return ($entry[2] == "next");
    });
    if (count($nextpage)) {
        return reset($nextpage)[1];
    }
    return '';
}

/**
 * Update one course in Canvas
 * This is the function called for change events from rabbitmq. It is also
 * called from check_canvas_structure() (in turn called from cronjob) and
 * full_sync() (for every single active course in tp).
 *
 * @param string $courseid e.g "INF-1100"
 * @param string $semesterid e.g "18v"
 * @param string $termnr
 * @return void
 */
function update_one_tp_course_in_canvas(string $courseid, string $semesterid, string $termnr)
{
    global $log, $tpclient;

    $timetable = $tpclient->get("1.4/", ['query' => ['id' => $courseid, 'sem' => $semesterid, 'termnr' => $termnr]]);
    if ($timetable->getStatusCode() != 200) {
        $log->critical("Could not get timetable from TP", array('courseid', $courseid));
        return;
    }
    $timetable = json_decode($timetable->getBody(), true);

    $log->debug("TP timetable", array('timetable' => $timetable));

    // Fetch courses
    $canvas_courses = fetch_and_clean_canvas_courses($courseid, $semesterid, $termnr, false);
    if (empty($canvas_courses)) {
        return;
    }

    if (count($canvas_courses) == 1) { // Only one course in canvas
        // Put everything there
        $tdata = [];
        if (isset($timetable['data'])) {
            // Just merge group and plenary to a single array
            $tdata = array_merge($timetable['data']['group'], $timetable['data']['plenary']);
        }
        add_timetable_to_one_canvas_course(reset($canvas_courses), $tdata, $timetable['courseid']);
    } else { // More than one course in Canvas. There are probably variants here
        // Find UE - several versions of a course might pose a problem here
        $ue = array_filter($canvas_courses, function (array $course) {
            if (stripos($course['sis_course_id'], 'UE_') === false) {
                return false;
            }
            return true;
        });
        // Find UA
        $ua = array_filter($canvas_courses, function (array $course) {
            if (stripos($course['sis_course_id'], 'UA_') === false) {
                return false;
            }
            return true;
        });

        $group_timetable = [];
        if (isset($timetable['data']) && isset($timetable['data']['group'])) {
            $group_timetable = $timetable['data']['group'];
        }

        if (count($ua)) {
            add_timetable_to_canvas($ua, $group_timetable, $timetable['courseid']);
        }

        $plenary_timetable = [];
        if (isset($timetable['data']) && isset($timetable['data']['plenary'])) {
            $plenary_timetable = $timetable['data']['plenary'];
        }

        if (count($ue)) {
            add_timetable_to_one_canvas_course(reset($ue), $plenary_timetable, $timetable['courseid']);
        }
    }
}

/**
 * Subscribe to message queue and update when courses change
 * This is what runs as a service
 *
 * @return void
 */
function queue_subscriber()
{
    global $log;

    // Connect to the RabbitMQ Server
    $connection = new AMQPStreamConnection($_SERVER['mq_host'], 5672, $_SERVER['mq_user'], $_SERVER['mq_password']);

    // Create our channel
    $channel = $connection->channel();
    /** @todo $channel->prefetch(1) */

    // Get exchange
    $channel->exchange_declare($_SERVER['mq_exchange'], 'fanout', false, true, false);

    // Get our queue
    list($queue_name, ,) = $channel->queue_declare($_SERVER['mq_queue'], false, true);
    $channel->queue_bind($queue_name, $_SERVER['mq_exchange']);

    // Subscribe to queue
    $channel->basic_consume($queue_name, '', false, true, false, false, "queue_process");

    while ($channel->is_consuming()) {
        $channel->wait();
    }

    $log->info("Normal exit, channel closed");
    $channel->close();
    $connection->close();
}

/**
 * Process a received queue message
 *
 * @param PhpAmqlLib\Message\AMQPMessage $msg Message received.
 * @return void
 */
function queue_process(PhpAmqlLib\Message\AMQPMessage $msg)
{

    global $log;

    $log->info("Message received from RabbitMQ", ['message' => $msg]);

    /** @todo Don't ack until processing is verified as successful */
    // Don't ack if dryrun is on
    if ($_SERVER['dryrun'] != 'on') {
        $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
    }

    $course = json_decode($msg->body);
    if ((strpos($course['id'], 'BOOKING') === false ) && (strpos($course['id'], 'EKSAMEN') === false)) {
        // Ignore BOOKING and EKSAMEN messages
        $course_key = "{$course['id']}-{$course['terminrr']}-{$course['semesterid']}";

        // Stupid argument wrapping for non-threaded execution
        $t_id = $course['id'];
        $t_semesterid = $course['semesterid'];
        $t_terminnr = $course['terminnr'];
        /** @todo error handling */
        update_one_tp_course_in_canvas($t_id, $t_semesterid, $t_terminnr);
    }
}
