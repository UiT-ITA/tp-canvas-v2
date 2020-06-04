<?php declare(strict_types=1);
/**
 * Tp Schedule
 * An object-oriented representation of an entire Tp schedule
 */

namespace TpCanvas;

use \Psr\Log\Loggerinterface;

class TpSchedule
{
    public TPClient $tpclient; // TP REST client
    public Loggerinterface $logger; // PSR-7 logger object
    public object $sourceobject; // json return from Tp webservice

    public string $semester;
    public string $id;
    public int $termnr;

    // These are calculated to know how large span we look at
    public string $firstsemester;
    public int $firstterm;
    public string $lastsemester;
    public int $lastterm;

    public array $activities = [];

    /*
    * Constructor
    * @param TPClient $tpclient Tp REST client
    * @param Loggerinterface $logger Logger object
    * @param string $semester semester id (ie "20v")
    * @param string $id course id (ie "FKS-1120")
    * @param int $termnr term number
    * @return void
    */
    public function __construct(TPClient $tpclient, Loggerinterface $logger, string $semester, string $id, int $termnr)
    {
        // Store dependency objects
        $this->tpclient = $tpclient;
        $this->logger = $logger;

        // Store key values
        $this->semester = $semester;
        $this->id = $id;
        $this->termnr = $termnr;

        // Calculate first semester
        // Simplest case, we were pointed to the first semester initially
        $this->firstsemester = $semester;
        $this->firstterm = 1;
        // If not, subtract back to 1
        if ($termnr != 1) {
            $semnumeric = string_to_semnr($semester);
            $semnumeric = $semnumeric - (0.5 * ($termnr - 1));
            $this->firstsemester = semnr_to_string($semnumeric);
            $this->firstterm = 1;
        }
    
        // Calculate last semester
        $maxsem = string_to_semnr($_SERVER['maxsem']);
        $thissem = string_to_semnr($semester);
        // Simplest case, we're within max
        $this->lastsemester = $semester;
        $this->lastterm = $termnr;
        if ($thissem < $maxsem) {
            $termsmore = ($maxsem - $thissem) * 2;
            $this->lastsemester = semnr_to_string($maxsem);
            $this->lastterm = $termnr + (int) $termsmore;
        }

        // Fetch schedule from TP
        $this->fetchTPSchedule();

        if (isset($this->sourceobject->data->group)) {
            $this->activities['group'] = [];
            foreach ($this->sourceobject->data->group as $index => $activity) {
                $this->activities['group'][$index] = new TpActivity($tpclient, $logger, $this, $activity);
            }
        }
        if (isset($this->sourceobject->data->plenary)) {
            $this->activities['plenary'] = [];
            foreach ($this->sourceobject->data->plenary as $index => $activity) {
                $this->activities['plenary'][$index] = new TpActivity($tpclient, $logger, $this, $activity);
            }
        }
    }

    /**
     * Fetch the entire (relevant) schedule for a course from TP
     * This iterates through all semesters, collecting each schedule and merges
     * them into the object for the first semester. Stores the result in
     * $this->schedule
     *
     * @param string $semesterid
     * @param string $courseid
     * @param integer $termnr
     * @return void
     */
    public function fetchTPSchedule(): void
    {
        $schedule = null;

        // We use an internal representation of %year + (%isautumn ? 0.5 : 0) for iteration
        $thissemnr = string_to_semnr($this->firstsemester);

        for ($term = $this->firstterm; $term <= $this->lastterm; $term++) {
            // Warning: might throw exception
            $timetable = $this->tpclient->schedule(semnr_to_string($thissemnr), $this->id, $term);
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
        $this->sourceobject = $schedule; // Save result
    }
}
