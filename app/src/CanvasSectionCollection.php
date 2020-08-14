<?php declare(strict_types=1);

/**
 * Canvas Section Collection
 * An iterator of canvas sections
 */

namespace TpCanvas;

use Psr\Log\Loggerinterface;

class CanvasSectionCollection extends CanvasCollection
{
    protected CanvasCourse $course; // The course this collection belongs to
    protected ?array $sections = null;

    /*
    * Constructor
    * @param CanvasClient $canvasclient Canvas REST client
    * @param Loggerinterface $logger Logger object
    * @param CanvasAccount $account The account we want to iterate over
    * @return void
    */
    public function __construct(CanvasClient $canvasclient, Loggerinterface $logger, CanvasCourse $course)
    {
        parent::__construct($canvasclient, $logger);
        $this->course = $course;
    }

    /**
     * Get entire list of sections from REST api
     *
     * @return array Array of stdClass objects
     */
    public function getList(): array
    {
        if (is_null($this->sections)) {
            $this->sections = $this->canvasclient->courses_sections($this->course->id);
        }
        return $this->sections;
    }

    /**
     * Get a single section from the REST api
     *
     * @param integer $id
     * @return object|null An stdClass object
     */
    public function getSingle(int $id): ?object
    {
        if (is_null($this->sections)) {
            $this->sections = $this->canvasclient->courses_sections($this->course->id);
        }
        return $this->sections[$id];
    }

    /**
     * Create a section object from a stdClass object
     *
     * @param object $element
     * @return CanvasSection
     */
    public function createElementInstance(object $element): CanvasSection
    {
        return new CanvasSection($this->canvasclient, $this->logger, $element);
    }
}
