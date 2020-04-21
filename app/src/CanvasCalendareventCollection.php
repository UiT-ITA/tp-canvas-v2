<?php declare(strict_types=1);
/**
 * Canvas Calendarevent Collection
 * An iterator of canvas calendar events
 */

namespace TpCanvas;

use \Psr\Log\Loggerinterface;

class CanvasCalendareventCollection extends CanvasCollection
{
    protected CanvasCourse $course; // The course this collection belongs to

    /*
    * Constructor
    * @param CanvasClient $canvasclient Canvas REST client
    * @param Loggerinterface $logger Canvas API key
    * @param CanvasAccount $account The account we want to iterate over
    * @return void
    */
    public function __construct(CanvasClient $canvasclient, Loggerinterface $logger, CanvasCourse $course)
    {
        parent::__construct($canvasclient, $logger);
        $this->course = $course;
    }

    /**
     * Get entire list of calendarevents from REST api
     *
     * @return array Array of stdClass objects
     */
    public function getList(): array
    {
        return $this->canvasclient->calendar_events(['context_codes[]' => "course_{$this->course->id}"]);
    }

    /**
     * Get a single calendarevent from the REST api
     *
     * @param integer $eventid
     * @return object|null An stdClass object
     */
    public function getSingle(int $eventid): ?object
    {
        return $this->canvasclient->calendar_events_get($eventid);
    }

    /**
     * Create a CanvasCalendarevent object from a stdClass object
     *
     * @param object $element
     * @return CanvasCalendarevent
     */
    public function createElementInstance(object $element): CanvasCalendarevent
    {
        return new CanvasCalendarevent($this->canvasclient, $this->logger, $element);
    }
}
