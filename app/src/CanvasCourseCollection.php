<?php declare(strict_types=1);
/**
 * Canvas Course Collection
 * An iterator of canvas courses
 */

namespace TpCanvas;

use \Psr\Log\Loggerinterface;

class CanvasCourseCollection extends CanvasCollection
{
    protected CanvasAccount $account; // The account this collection belongs to

    /*
    * Constructor
    * @param CanvasClient $canvasclient Canvas REST client
    * @param Loggerinterface $logger Logger object
    * @param CanvasAccount $account The account we want to iterate over
    * @return void
    */
    public function __construct(CanvasClient $canvasclient, Loggerinterface $logger, CanvasAccount $account)
    {
        parent::__construct($canvasclient, $logger);
        $this->account = $account;
    }

    /**
     * Get entire list of courses from the REST api
     *
     * @return array An array of stdClass objects as returned from the API
     */
    public function getList(): array
    {
        return $this->canvasclient->accounts_courses($this->account->id);
    }

    /**
     * Get a single course object from the REST api
     *
     * @param integer $courseid
     * @return object A stdClass object as returned from the API or null
     */
    public function getSingle(int $courseid): ?object
    {
        return $this->canvasclient->courses_get($courseid);
    }

    /**
     * Create a CanvasCourse object from a stdClass object
     *
     * @param object $element
     * @return CanvasCourse
     */
    public function createElementInstance(object $element): CanvasCourse
    {
        return new CanvasCourse($this->canvasclient, $this->logger, $element);
    }
}
