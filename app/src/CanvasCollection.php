<?php declare(strict_types=1);
/**
 * Canvas Collection grandfather
 * An iterator of canvas accounts
 */

namespace TpCanvas;

use \Psr\Log\Loggerinterface;

abstract class CanvasCollection implements \SeekableIterator, \ArrayAccess
{
    protected array $elements = []; // Elements of stdClass returned from REST
    protected array $keys = []; // List of keys used - helps when seeking
    protected array $instances = []; // Objects instanciated from the real class
    protected int $position = 0; // Current position for the SeekableIterator

    protected CanvasClient $canvasclient; // Canvas client for REST calls
    protected Loggerinterface $logger; // PSR Logger for logging

    #region Abstract methods

    /**
     * Get entire list for this collection
     *
     * @return array Array of stdClass object
     */
    abstract protected function getList(): array;

    /**
     * Get a single element for this collection
     *
     * @param integer $id
     * @return object|null stdClass object or null
     */
    abstract protected function getSingle(int $id): ?object;

    /**
     * Create a real class instance from an stdClass element
     *
     * @param object $element The stdClass object as returned by the REST api
     * @return object Object instanciated from class.
     */
    abstract protected function createElementInstance(object $element): object;

    #endregion Abstract methods

    /*
    * Constructor
    * @param CanvasClient $canvasclient Canvas REST client
    * @param Loggerinterface $logger Logger object
    * @return void
    */
    public function __construct(CanvasClient $canvasclient, Loggerinterface $logger)
    {
        $this->canvasclient = $canvasclient;
        $this->logger = $logger;
    }

    /**
     * Fill elements array with entire list
     *
     * @return void
     */
    private function fillElements(): void
    {
        if (empty($this->elements)) {
            $this->elements = $this->getList();
            $this->keys = array_column($this->elements, 'id');
            $this->elements = array_combine($this->keys, $this->elements);
        }
    }

    /**
     * Return an instanciated object
     *
     * @param integer $id The id to fetch
     * @return object|null Instanciated object or null of not available
     */
    private function getElementInstance(int $id): ?object
    {
        if (!isset($this->instances[$id])) {
            if (!isset($this->elements[$id])) {
                $object = $this->getSingle($id);
                if (is_null($object)) {
                    return null;
                }
                $this->elements[$id] = $object;
            }
            $this->instances[$id] = $this->createElementInstance($this->elements[$id]);
        }
        return $this->instances[$id];
    }

    #region SeekableIterator interface

    /**
     * Set iterator to a specific position
     *
     * @param integer $position
     * @return void
     */
    public function seek(int $position): void
    {
        $this->position = $position;
    }

    /**
     * Get element pointed to by iterator
     *
     * @return object
     */
    public function current(): object
    {
        $this->fillElements(); // Expensive, entire list needed
        $id = $this->keys[$this->position];
        $object = $this->getElementInstance($id);
        return $object;
    }

    /**
     * Return the key pointed to by iterator
     *
     * @return integer key (which is id column for most objects)
     */
    public function key(): int
    {
        $this->fillElements(); // Expensive, entire list needed
        return $this->keys[$this->position];
    }

    /**
     * Advance the iterator tonext position
     *
     * @return void
     */
    public function next(): void
    {
        $this->position++;
    }

    /**
     * Reset iterator to start
     *
     * @return void
     */
    public function rewind(): void
    {
        $this->position = 0;
    }

    /**
     * Check if iterator points to a valid position. An invalid position is
     * either before start of list, or after end of list.
     *
     * @return boolean valid position
     */
    public function valid(): bool
    {
        $this->fillElements(); // Expensive, entire list needed
        if (!isset($this->keys[$this->position])) {
            return false;
        }
        return true;
    }

    #endregion SeekableIterator interface

    #region ArrayAccess interface

    /**
     * Check if there is an element at a given offset
     *
     * @param mixed $offset Key
     * @return boolean is there an element there
     */
    public function offsetExists($offset): bool
    {
        $object = $this->getElementInstance($offset);
        if (is_null($object)) {
            return false;
        }
        return true;
    }

    /**
     * Get the element at a given offset
     *
     * @param mixed $offset Key
     * @return object|null The element, or null if not existing
     */
    public function offsetGet($offset): object
    {
        return $this->getElementInstance($offset);
    }

    /**
     * Set an element at a given offset
     *
     * @param mixed $offset Key
     * @param mixed $value Element
     * @return void
     */
    public function offsetSet($offset, $value): void
    {
        throw new ErrorException("Save not implemented");
    }

    /**
     * Unset an element at a given offset
     *
     * @param mixed $offset Key
     * @return void
     */
    public function offsetUnset($offset): void
    {
        $object = $this->offsetGet($offset);
        if (is_null($object)) {
            throw new ErrorException("Element not found for unset");
        }
        $object->delete();
        $key = array_search($offset, $this->keys);
        unset($this->elements[$offset]);
        unset($this->instances[$offset]);
        unset($this->keys[$key]);
    }

    #endregion ArrayAccess interface
}
