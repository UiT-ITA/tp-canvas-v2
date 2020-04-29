<?php declare(strict_types=1);
/**
 * Change list
 * A list of changes done to courses, to see if incoming changes are outdated.
 */

namespace TpCanvas;

use \Psr\Log\Loggerinterface;

class ChangeList
{
    protected Loggerinterface $logger; // PSR Logger
    protected array $changes; // Array of change timestamps. Keyed on course name, sorted with oldest first.

    /*
    * Constructor
    * @param Loggerinterface $logger Canvas API key
    * @return void
    */
    public function __construct(Loggerinterface $logger)
    {
        $this->logger = $logger;
        $this->changes = [];
    }

    /**
     * Check if a time is already covered
     *
     * @param string $courseid
     * @param string $time
     * @return boolean
     */
    public function check(string $courseid, string $time): bool
    {
        if (!isset($this->changes[$courseid])) {
            // No recollection
            return false;
        }
        if (\strtotime($time) > $this->changes[$courseid]) {
            // Incoming change is after last change
            return false;
        }
        return true;
    }

    /**
     * Store a new time
     *
     * @param string $courseid
     * @param string $time
     * @return void
     */
    public function set(string $courseid, string $time): void
    {
        if (isset($this->changes[$courseid])) {
            // Exists in list, delete and re-insert
            unset($this->changes[$courseid]);
            $this->changes[$courseid] = strtotime($time);
            return;
        }

        if (count($this->changes) > 100) {
            // If our list is at max, remove first element
            array_shift($this->changes);
        }
        $this->changes[$courseid] = strtotime($time);
    }
}
