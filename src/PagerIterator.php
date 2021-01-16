<?php

declare(strict_types=1);

namespace Solido\Pagination;

use Closure;
use Generator;
use Iterator;
use RuntimeException;
use Solido\Pagination\Accessor\DateTimeValueAccessor;
use Solido\Pagination\Accessor\ValueAccessor;
use Solido\Pagination\Accessor\ValueAccessorInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Traversable;

use function array_filter;
use function array_pop;
use function array_reverse;
use function array_slice;
use function array_values;
use function assert;
use function count;
use function crc32;
use function current;
use function implode;
use function is_array;
use function is_object;
use function iterator_to_array;
use function key;
use function next;
use function reset;
use function Safe\uasort;

class PagerIterator implements Iterator
{
    public const DEFAULT_PAGE_SIZE = 10;

    /**
     * Ordering information holder.
     */
    protected Orderings $orderBy;

    /**
     * The page size.
     */
    protected int $pageSize;

    /**
     * The current continuation token.
     */
    protected ?PageToken $token;

    /**
     * The object array to be paginated.
     *
     * @var array<object>
     */
    private array $objects;

    /** @var array<object>|null */
    private ?array $page;

    private bool $valid;

    /**
     * @param array<object>|Traversable<object> $objects
     * @param Orderings|string[]|string[][] $orderBy
     *
     * @phpstan-param Orderings|array<string>|array<string, 'asc'|'desc'>|array<array{string, 'asc'|'desc'}> $orderBy
     */
    public function __construct(iterable $objects, $orderBy)
    {
        $this->pageSize = self::DEFAULT_PAGE_SIZE;
        $this->token = null;
        $this->page = null;
        $this->valid = false;

        $this->orderBy = $orderBy instanceof Orderings ? $orderBy : new Orderings($orderBy);

        if (count($this->orderBy) < 2) {
            throw new RuntimeException('orderBy must have at least 2 "field" => "direction(ASC|DESC)". The first is the reference timestamp, the second is the checksum field.');
        }

        $objects = is_array($objects) ? $objects : iterator_to_array($objects);
        uasort($objects, Closure::fromCallable([$this, 'sort']));

        $this->objects = array_values($objects);
    }

    /**
     * Return the current element.
     *
     * @return mixed
     */
    public function current()
    {
        assert($this->page !== null);

        return current($this->page);
    }

    public function next(): void
    {
        assert($this->page !== null);
        $check = next($this->page);
        $this->valid = $check !== false || key($this->page) !== null;
    }

    /**
     * Return the key of the current element
     *
     * @return mixed
     */
    public function key()
    {
        assert($this->page !== null);

        return key($this->page);
    }

    public function valid(): bool
    {
        return $this->valid;
    }

    public function rewind(): void
    {
        if ($this->page === null) {
            $this->page = $this->getPage();
        }

        reset($this->page);
        $this->valid = count($this->page) > 0;
    }

    /**
     * Sets the page size.
     *
     * @return $this
     */
    public function setPageSize(int $pageSize = self::DEFAULT_PAGE_SIZE): self
    {
        $this->pageSize = $pageSize;
        $this->page = null;

        return $this;
    }

    /**
     * Sets the continuation token.
     *
     * @return $this
     */
    public function setToken(?PageToken $token): self
    {
        $this->token = $token;
        $this->page = null;

        return $this;
    }

    /**
     * Gets the token for the next page.
     */
    public function getNextPageToken(): ?PageToken
    {
        if ($this->page === null) {
            $this->rewind();
        }

        if (empty($this->page)) {
            return null;
        }

        $refObjects = iterator_to_array($this->getLastObjectsWithCommonTimestamp($this->page), false);

        return new PageToken($this->getOrderValueForObject($refObjects[0]), count($refObjects), $this->getChecksumForObjects($refObjects));
    }

    /**
     * Returns the objects to be paginated.
     *
     * @return array<object>
     */
    protected function getObjects(): array
    {
        return $this->objects;
    }

    /**
     * Filters the object array and return a subset of
     * eligible objects.
     *
     * @param object[] $objects
     *
     * @return object[]
     */
    protected function filterObjects(array $objects): array
    {
        $order = $this->orderBy[0];

        assert($this->token !== null);
        $referenceTimestamp = $this->token->getOrderValue();

        return array_filter($objects, static function ($entity) use ($order, $referenceTimestamp) {
            $value = static::getAccessor()->getValue($entity, $order[0]);

            return $order[1] === Orderings::SORT_ASC ? $value >= $referenceTimestamp : $value <= $referenceTimestamp;
        });
    }

    /**
     * Checks if token is still valid, comparing the checksum of the
     * head elements.
     *
     * @param object[] $objects
     */
    protected function checksumDiffers(array $objects): bool
    {
        assert($this->token !== null);
        $head = array_slice($objects, 0, $this->token->getOffset());

        $referenceObjects = $this->getLastObjectsWithCommonTimestamp($head);
        $referenceChecksum = $this->getChecksumForObjects($referenceObjects);

        return $this->token->getChecksum() !== $referenceChecksum;
    }

    /**
     * Checks if the reference datetime for the first object is equal
     * to the token ones. If not, something has changed in underlying data.
     *
     * @param object[] $objects
     */
    protected function orderValueDiffers(array $objects): bool
    {
        assert($this->token !== null);
        $reference = reset($objects);

        return $this->getOrderValueForObject($reference) !== $this->token->getOrderValue();
    }

    /**
     * Gets a value accessor.
     */
    protected static function getAccessor(): ValueAccessorInterface
    {
        static $accessor = null;
        if ($accessor === null) {
            $propertyAccess = PropertyAccess::createPropertyAccessor();
            $accessor = new DateTimeValueAccessor(new ValueAccessor($propertyAccess));
        }

        return $accessor;
    }

    /**
     * Main function of the Pager:
     * - if we have no objects to paginate, we must return an empty array. This means that
     *      a) we have no objects in the database OR
     *      b) from the given timestamp on, there are no results.
     *
     * - if the token is null it means that this is the first call (eg. /resource, with no continuation token)
     *   we return an array with size as $pageSize, starting from the first element of the array
     *
     *   In every other case, we must filter the objects array, which contains every item in the database.
     *   We use the timestamp inside the token as a reference, compared with the first field of the orderBy array.
     *
     * - if there's a difference in timestamps or checksums the fallback procedure is:
     *   return the first $pageSize elements of the filtered array
     *
     * - if checksum and timestamp are ok the procedure is:
     *   return the first $pageSize elements of the filtered array, taking the offset into account
     *
     * @return array<object>
     */
    private function getPage(): array
    {
        $objects = $this->getObjects();
        if ($this->pageSize === 0 || count($objects) === 0) {
            return [];
        }

        if ($this->token === null) {
            //First call: continuous token is not present, so we return the first page of objects
            return array_slice($objects, 0, $this->pageSize);
        }

        $objects = $this->filterObjects($objects);
        if (count($objects) === 0) {
            return [];
        }

        if ($this->orderValueDiffers($objects) || $this->checksumDiffers($objects)) {
            // Fallback: deliver all the first-page of the filtered elements involved (the elements >= timestamp requested)
            return array_slice($objects, 0, $this->pageSize);
        }

        /*
         * else: return the page taking into account the offset of the previous request:
         *  This means multiple objects have the same timestamp and different offset, so this value
         *  must be taken into account
         */
        return array_slice($objects, $this->token->getOffset(), $this->pageSize);
    }

    /**
     * This function will do two things:
     * - starting from the bottom, get the first element (so, the last of the array)
     * - create an array with the previously found element and every other element with the same timestamp.
     *
     * @param array<object> $objects
     */
    private function getLastObjectsWithCommonTimestamp(array $objects): Generator
    {
        $reference = array_pop($objects);
        $referenceOrderValue = $this->getOrderValueForObject($reference);

        yield $reference;

        foreach (array_reverse($objects, false) as $object) {
            if ($this->getOrderValueForObject($object) !== $referenceOrderValue) {
                break;
            }

            yield $object;
        }
    }

    /**
     * Gets the reference datetime object for the given object.
     *
     * @param mixed $object
     *
     * @return mixed
     */
    private function getOrderValueForObject($object)
    {
        return static::getAccessor()->getValue($object, $this->orderBy[0][0]);
    }

    /**
     * This must return the crc32 of "all the last objects sharing the same timestamp" ids.
     *
     * @param iterable<object> $objects
     */
    private function getChecksumForObjects(iterable $objects): int
    {
        $idArray = [];

        $order = $this->orderBy[1];
        $valueAccessor = static::getAccessor();

        foreach ($objects as $object) {
            assert(is_object($object));
            $idArray[] = $valueAccessor->getValue($object, $order[0]);
        }

        return crc32(implode(',', $idArray));
    }

    // phpcs:disable SlevomatCodingStandard.Classes.UnusedPrivateElements.UnusedMethod

    /**
     * Sorting method, used to sort object array.
     *
     * @param mixed $a
     * @param mixed $b
     */
    private function sort($a, $b): int
    {
        $accessor = static::getAccessor();
        $ord1 = $ord2 = [];

        foreach ($this->orderBy as [$field, $direction]) {
            $valueA = (string) $accessor->getValue($a, $field);
            $valueB = (string) $accessor->getValue($b, $field);

            if ($direction === Orderings::SORT_ASC) {
                $ord1[] = $valueA;
                $ord2[] = $valueB;
            } else {
                $ord1[] = $valueB;
                $ord2[] = $valueA;
            }
        }

        return $ord1 <=> $ord2;
    }

    // phpcs:enable
}
