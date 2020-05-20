<?php declare(strict_types=1);
/**
 * Tp Activity
 * An object-oriented representation of a Tp activity
 */

namespace TpCanvas;

use \Psr\Log\Loggerinterface;

class TpActivity
{
    public TPClient $tpclient; // TP REST client
    public Loggerinterface $logger; // PSR-7 logger object
    public TpSchedule $schedule; // Our parent schedule
    public object $sourceobject; // stdclass object from Tp webservice

    /*
    * Constructor
    *
    * @param TPClient $tpclient Tp REST client
    * @param Loggerinterface $logger Logger object
    * @param TpSchedule $schedule the schedule we belong to
    * @param object $activity stdclass object from Tp webservice
    * @return void
    */
    public function __construct(TPClient $tpclient, Loggerinterface $logger, TpSchedule $schedule, object $activity)
    {
        // Store dependency objects
        $this->tpclient = $tpclient;
        $this->logger = $logger;
        $this->schedule = $schedule;
        $this->sourceobject = $activity;
    }
}
