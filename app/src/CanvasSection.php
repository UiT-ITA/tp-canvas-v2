<?php declare(strict_types=1);

/**
 * Canvas Course
 * An object-oriented representation of a Canvas course
 */

namespace TpCanvas;

use Psr\Log\Loggerinterface;
use GuzzleHttp\Exception\ClientException;

class CanvasSection extends CanvasObject
{
    #region magic methods
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
    }

    /**
     * Magical __toString method
     *
     * @return string A string representation of this object
     */
    public function __toString(): string
    {
        return "{$this->id} '{$this->name}' SIS_Section {$this->sourceobject->sis_section_id} SIS_Course {$this->sourceobject->sis_course_id} XLIST {$this->sourceobject->nonxlist_course_id} STUDENTS {$this->sourceobject->total_students}";
    }

    #endregion magic methods

    #region CanvasObject methods

    public function save(): void
    {
        throw new ErrorException("Not implemented");
    }

    #endregion CanvasObject methods

    public function delete(): void
    {
        throw new ErrorException("Not implemented");
    }
}
