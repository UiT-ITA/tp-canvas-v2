<?php declare(strict_types=1);
/**
 * Canvas Account
 * An object-oriented representation of a Canvas account
 */

namespace TpCanvas;

use \Psr\Log\Loggerinterface;

class CanvasAccount extends CanvasObject
{

    public CanvasCourseCollection $courses; // Courses for this account

    /*
    * Constructor
    * @param CanvasClient $canvasclient Canvas REST client
    * @param Loggerinterface $logger Canvas API key
    * @param object $sourceobject The source object to base this object on
    * @return void
    */
    public function __construct(CanvasClient $canvasclient, Loggerinterface $logger, object $sourceobject = null)
    {
        parent::__construct($canvasclient, $logger, $sourceobject);
        $this->courses = new CanvasCourseCollection($canvasclient, $logger, $this);
    }

    public function save(): void
    {
        throw new ErrorException("Not implemented");
    }

    public function delete(): void
    {
        throw new ErrorException("Not implemented");
    }
}
