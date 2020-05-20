<?php declare(strict_types=1);
/**
 * Canvas Course
 * An object-oriented representation of a Canvas course
 */

namespace TpCanvas;

use \Psr\Log\Loggerinterface;

class CanvasCourse extends CanvasObject
{

    public CanvasCalendareventCollection $calendarevents; // Calendarevents for this course

    /*
    * Constructor
    * @param CanvasClient $canvasclient Canvas REST client
    * @param Loggerinterface $logger Logger object
    * @param object $sourceobject The source object to base this object on
    * @return void
    */
    public function __construct(CanvasClient $canvasclient, Loggerinterface $logger, object $sourceobject = null)
    {
        parent::__construct($canvasclient, $logger, $sourceobject);
        $this->calendarevents = new CanvasCalendareventCollection($canvasclient, $logger, $this);
    }

    public function save(): void
    {
        throw new ErrorException("Not implemented");
    }

    public function delete(): void
    {
        throw new ErrorException("Not implemented");
    }

    public function getSISID(): string
    {
        return $this->sourceobject->sis_course_id;
    }

    public function isPublished(): bool
    {
        if (
            $this->sourceobject->workflow_state == 'available'
            || $this->sourceobject->workflow_state == 'completed'
        ) {
            return true;
        }
        if (
            $this->sourceobject->workflow_state == 'unpublished'
            || $this->sourceobject->workflow_state == 'deleted'
        ) {
            return false;
        }
        throw new UnexpectedValueException("Unknown Canvas workflow_state");
    }
}
