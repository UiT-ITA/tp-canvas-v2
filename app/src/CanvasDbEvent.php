<?php declare(strict_types=1);

namespace TpCanvas;

/**
 * Activerecord emulation wrapper class
 */
class CanvasDbEvent
{
    public int $id; // primary key
    public int $canvas_course_id; // foreign key
    public int $canvas_id; // canvas id

    private $pdoclient;

    /**
     * CanvasDbEvent constructor
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
     * @return array Array of CanvasDbEvent objects
     */
    public static function findByCanvasCourseId(int $canvascourseid): array
    {
        global $pdoclient;
        $stmt = $pdoclient->prepare("SELECT * FROM canvas_events WHERE canvas_course_id = ?");
        $stmt->execute(array($canvascourseid));
        $result = array();
        while ($event = $stmt->fetchObject('TpCanvas\\CanvasDbEvent')) {
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
        if (isset($this->id)) {
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
