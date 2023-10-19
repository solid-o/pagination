<?php

declare(strict_types=1);

namespace Solido\Pagination\Doctrine\DBAL;

use Doctrine\DBAL\Driver\ResultStatement;
use Doctrine\DBAL\FetchMode;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use Refugis\DoctrineExtra\DBAL\IteratorTrait;
use Refugis\DoctrineExtra\ObjectIteratorInterface;
use RuntimeException;
use Solido\Pagination\Orderings;
use Solido\Pagination\PageNumber;
use Solido\Pagination\PageOffset;
use Solido\Pagination\PagerIterator as BaseIterator;
use Solido\Pagination\PageToken;

use function array_map;
use function assert;
use function call_user_func;
use function class_exists;
use function count;
use function is_callable;
use function is_object;
use function json_decode;
use function json_encode;
use function strtoupper;

use const JSON_THROW_ON_ERROR;

final class PagerIterator extends BaseIterator implements ObjectIteratorInterface
{
    use IteratorTrait;

    /**
     * @param Orderings|string[]|string[][] $orderBy
     * @phpstan-param Orderings|array<string>|array<string, 'asc'|'desc'>|array<array{string, 'asc'|'desc'}> $orderBy
     */
    public function __construct(QueryBuilder $queryBuilder, Orderings|array $orderBy = new Orderings([]))
    {
        $this->queryBuilder = clone $queryBuilder;
        $this->totalCount = null;

        $this->apply(null);

        parent::__construct([], $orderBy);
    }

    public function current(): mixed
    {
        if (! $this->valid()) {
            return null;
        }

        if ($this->current === null) {
            assert(is_callable($this->callable));
            $this->current = call_user_func($this->callable, $this->currentElement);
        }

        return $this->current;
    }

    public function next(): void
    {
        parent::next();

        $this->current = null;
        $current = parent::current();
        $this->currentElement = is_object($current) ? self::toArray($current) : $current;
    }

    public function rewind(): void
    {
        parent::rewind();

        $this->current = null;
        $current = parent::current();
        $this->currentElement = is_object($current) ? self::toArray($current) : $current;
    }

    /**
     * {@inheritdoc}
     */
    protected function filterObjects(array $objects): array
    {
        if ($this->currentPage instanceof PageToken) {
            return parent::filterObjects($objects);
        }

        return $objects;
    }

    /**
     * {@inheritdoc}
     */
    protected function getObjects(): array
    {
        $queryBuilder = clone $this->queryBuilder;
        $queryBuilder->setFirstResult(0);
        foreach ($this->orderBy as $key => [$field, $direction]) {
            $method = $key === 0 ? 'orderBy' : 'addOrderBy';
            $queryBuilder->{$method}($field, strtoupper($direction));
        }

        $limit = $this->pageSize;
        if ($this->currentPage instanceof PageToken) {
            if (count($this->orderBy) < 2) {
                throw new RuntimeException('orderBy must have at least 2 "field" => "direction(ASC|DESC)". The first is the reference timestamp, the second is the checksum field.');
            }

            $timestamp = $this->currentPage->getOrderValue();
            $limit += $this->currentPage->getOffset();
            $mainOrder = $this->orderBy[0];

            $direction = $mainOrder[1] === Orderings::SORT_ASC ? '>=' : '<=';
            $queryBuilder->andWhere($mainOrder[0] . ' ' . $direction . ' :timeLimit');
            $queryBuilder->setParameter('timeLimit', $timestamp, Types::TEXT);
        } elseif ($this->currentPage instanceof PageNumber) {
            $offset = ($this->currentPage->getPageNumber() - 1) * $limit;
            $queryBuilder->setFirstResult($offset);
        } elseif ($this->currentPage instanceof PageOffset) {
            $offset = $this->currentPage->getOffset();
            $queryBuilder->setFirstResult($offset);
        }

        $queryBuilder->setMaxResults($limit);
        if (class_exists(ResultStatement::class)) {
            $stmt = $queryBuilder->execute();
            assert($stmt instanceof ResultStatement);

            return $stmt->fetchAll(FetchMode::STANDARD_OBJECT); /* @phpstan-ignore-line */
        }

        $result = $queryBuilder->executeQuery();

        return array_map(static fn (array $d): object => (object) $d, $result->fetchAllAssociative());
    }

    /** @return array<object> */
    private static function toArray(object $rowObject): array
    {
        return json_decode(json_encode($rowObject, JSON_THROW_ON_ERROR, 512), true, 512, JSON_THROW_ON_ERROR);
    }
}
