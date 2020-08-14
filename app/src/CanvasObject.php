<?php declare(strict_types=1);
/**
 * Canvas Object
 * An object-oriented wrapper for objects based on generic objects (from json)
 */

namespace TpCanvas;

use \Psr\Log\Loggerinterface;

abstract class CanvasObject
{
    protected CanvasClient $canvasclient; // Canvas REST client
    protected Loggerinterface $logger; // PSR Logger
    protected object $sourceobject; // The result of json_decode() on the REST payload

    /**
     * Delete the object from Canvas. This function returns true if the object
     * is gone from Canvas, either by deletion or if it wasn't there to begin
     * with. Throws exceptions.
     *
     * @param CanvasClient $canvasclient
     * @param int $id
     * @return void
     */
    abstract public function delete(): void;

    /**
     * Save the current object to Canvas. Note that the object may or may not
     * have an id set. Throws exceptions.
     *
     * @return void
     */
    abstract public function save(): void;

    /*
    * Constructor
    * @param CanvasClient $canvasclient Canvas REST client
    * @param Loggerinterface $logger Canvas API key
    * @param object $sourceobject The source object to base this object on
    * @return void
    */
    public function __construct(CanvasClient $canvasclient, Loggerinterface $logger, object $sourceobject = null)
    {
        $this->canvasclient = $canvasclient;
        $this->logger = $logger;
        $this->sourceobject = $sourceobject;
    }

    /**
     * Magical getter
     *
     * @param string $name property to get
     * @return mixed Returns property value
     */
    public function __get(string $name)
    {
        if (!isset($this->sourceobject->{$name})) {
            $this->logger->warning(
                "Undefined property '{$name}' fetched",
                ["object" => $this, "backtrace" => debug_backtrace()]
            );
            return null;
        }
        return $this->sourceobject->{$name};
    }

    /**
     * Magical isset
     *
     * @param string $name property
     * @return boolean is the property set
     */
    public function __isset(string $name): bool
    {
        return isset($this->sourceobject->{$name});
    }

    /**
     * Magical setter
     *
     * @param string $name Property name
     * @param mixed $value Property value
     * @return void
     */
    public function __set(string $name, $value): void
    {
        $this->sourceobject->{$name} = $value;
    }

    /**
     * Magical debuginfo
     *
     * @return array properties
     */
    public function __debugInfo()
    {
        return (array) $this->sourceobject;
    }
}
