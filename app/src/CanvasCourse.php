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

    /**
     * Decode sis course id
     *
     * @return array
     */
    public function getSISElements(): array
    {
        $elements = \explode('_', $this->getSISID());
        if ($elements[0] == 'UE') {
            return [
                'type' => $elements[0],
                'institution' => $elements[1],
                'course' => $elements[2],
                'version' => $elements[3],
                'year' => $elements[4],
                'season' => $elements[5],
                'termnr' => $elements[6],
                'tpsemester' => \substr($elements[4], 2, 2) . \strtolower(\substr($elements[5], 0, 1))
            ];
        }
        if ($elements[0] == 'UA') {
            return [
                'type' => $elements[0],
                'institution' => $elements[1],
                'course' => $elements[2],
                'version' => $elements[3],
                'year' => $elements[4],
                'season' => $elements[5],
                'termnr' => $elements[6],
                'actid' => $elements[7],
                'tpsemester' => \substr($elements[4], 2, 2) . \strtolower(\substr($elements[5], 0, 1))
            ];
        }
        throw new UnexpectedValueException("Unknown SIS type encountered");
    }


    public function __toString()
    {
        $out = '';
        $out .= "ID:{$this->sourceobject->id} ";
        $out .= "SIS:{$this->sourceobject->sis_course_id} ";
        $out .= "NAME:{$this->sourceobject->name} ";
        $out .= ($this->isPublished() ? 'PUBLISHED' : 'UNPUBLISHED') . ' ';
        return $out;
    }
}
