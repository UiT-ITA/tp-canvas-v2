<?php declare(strict_types=1);

namespace TpCanvas;

/**
 * Activerecord emulation wrapper class
 *
 * @property-read array canvas_events An array of all CanvasDbEvent objects belonging to this course
 */
class CanvasDbCourse
{
    public ?int $id;
    public ?int $canvas_id;
    public ?string $name;
    public ?string $course_code;
    public ?string $sis_course_id;

    private ?object $pdoclient;

    /**
     * CanvasDbCourse constructor
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
     * @return CanvasDbCourse course object, either with values (if found) or completely blank (if not found)
     */
    public static function find_or_create(int $canvas_id): CanvasDbCourse
    {
        global $pdoclient;
        $stmt = $pdoclient->prepare("SELECT * FROM canvas_courses WHERE canvas_id = ?");
        $stmt->execute(array($canvas_id));
        $result = $stmt->fetchObject('TpCanvas\\CanvasDbCourse');
        if ($result === false) {
            $result = new CanvasDbCourse;
            $result->canvas_id = $canvas_id;
        }
        return $result;
    }

    /**
     * Find single course by sis course id
     *
     * @param string $sis_course_id the sis course id to search with
     * @return CanvasDbCourse|null found course or null if none found
     */
    public static function find(string $sis_course_id): ?CanvasDbCourse
    {
        global $pdoclient;
        $stmt = $pdoclient->prepare("SELECT * FROM canvas_courses WHERE sis_course_id = ?");
        $stmt->execute(array($sis_course_id));
        $result = $stmt->fetchObject('TpCanvas\\CanvasDbCourse');
        if ($result === false) {
            return null;
        }
        return $result;
    }

    /**
     * Find all courses matching a sis_course_id wildcard search
     *
     * @param string $like the condition to search with, including % chars
     * @return array Array of CanvasDbCourse objects
     */
    public static function findBySisLike(string $like): array
    {
        global $pdoclient;
        $stmt = $pdoclient->prepare("SELECT * FROM canvas_courses WHERE sis_course_id like ?");
        $stmt->execute(array($like));
        $result = array();
        while ($course = $stmt->fetchObject('TpCanvas\\CanvasDbCourse')) {
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
        if (isset($this->id)) {
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
                return CanvasDbEvent::findByCanvasCourseId($this->id);
            }
            return array();
        }
        return null;
    }
}
