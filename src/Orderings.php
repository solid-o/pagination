<?php

declare(strict_types=1);

namespace Solido\Pagination;

use ArrayAccess;
use Countable;
use Generator;
use IteratorAggregate;

use function assert;
use function count;
use function is_array;
use function is_int;
use function is_string;
use function Safe\preg_match;
use function strtolower;

/**
 * Represents the orderings set for pager.
 */
final class Orderings implements Countable, IteratorAggregate, ArrayAccess
{
    public const SORT_ASC = 'asc';
    public const SORT_DESC = 'desc';

    /** @var array<array<string>> */
    private array $orderings;

    /**
     * $orderings accepts the following formats:
     * - field name as value: means the field should be ascending ordered
     * - field name as key, asc or desc as value
     * - array containing field name and the direction.
     *
     * @param string[]|string[][] $orderings
     *
     * @phpstan-param array<string>|array<string, 'asc'|'desc'>|array<array{string, 'asc'|'desc'}> $orderings
     */
    public function __construct(array $orderings)
    {
        $this->orderings = self::normalize($orderings);
    }

    public function getIterator(): Generator
    {
        yield from $this->orderings;
    }

    /**
     * Count ordering rules.
     */
    public function count(): int
    {
        return count($this->orderings);
    }

    /**
     * Whether a offset exists
     *
     * @param string $offset
     */
    public function offsetExists($offset): bool
    {
        return isset($this->orderings[$offset]);
    }

    /**
     * Offset to retrieve
     *
     * @param int $offset
     *
     * @return array<string>
     */
    public function offsetGet($offset): array
    {
        return $this->orderings[$offset];
    }

    /**
     * Offset to set
     *
     * @param string|int|null $offset
     * @param mixed $value
     */
    public function offsetSet($offset, $value): void
    {
        // Do nothing.
    }

    /**
     * Offset to unset
     *
     * @param string|int|null $offset
     */
    public function offsetUnset($offset): void
    {
        // Do nothing.
    }

    /**
     * Normalizes the orderings array.
     *
     * @param string[]|string[][] $orderings
     *
     * @return array<array<string>>
     *
     * @phpstan-param array<string>|array<string, 'asc'|'desc'>|array<array{string, 'asc'|'desc'}> $orderings
     */
    private static function normalize(array $orderings): array
    {
        $normalized = [];
        foreach ($orderings as $field => $direction) {
            if (is_int($field)) {
                if (is_array($direction)) {
                    $normalized[] = [$direction[0], self::normalizeDirection($direction[1])];
                } else {
                    $normalized[] = [$direction, self::SORT_ASC];
                }
            } else {
                assert(is_string($direction));
                $normalized[] = [$field, self::normalizeDirection($direction)];
            }
        }

        return $normalized;
    }

    /**
     * Normalizes orderBy direction.
     */
    private static function normalizeDirection(string $direction): string
    {
        if (! preg_match('/' . self::SORT_ASC . '|' . self::SORT_DESC . '/i', $direction)) {
            throw new Exception\InvalidArgumentException('Invalid ordering direction "' . $direction . '"');
        }

        return strtolower($direction);
    }
}
