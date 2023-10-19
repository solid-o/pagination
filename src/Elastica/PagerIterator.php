<?php

declare(strict_types=1);

namespace Solido\Pagination\Elastica;

use DateTimeImmutable;
use Elastica\Query;
use Refugis\DoctrineExtra\IteratorTrait;
use Refugis\DoctrineExtra\ObjectIteratorInterface;
use Refugis\ODM\Elastica\Search\Search;
use Refugis\ODM\Elastica\Type\AbstractDateTimeType;
use Solido\Pagination\Exception\InvalidArgumentException;
use Solido\Pagination\Orderings;
use Solido\Pagination\PageNumber;
use Solido\Pagination\PageOffset;
use Solido\Pagination\PagerIterator as BaseIterator;
use Solido\Pagination\PageToken;

use function assert;
use function is_string;
use function Safe\sprintf;

final class PagerIterator extends BaseIterator implements ObjectIteratorInterface
{
    use IteratorTrait;

    private Search $search;
    private int|null $totalCount;

    /**
     * @param Orderings|string[]|string[][] $orderBy
     * @phpstan-param Orderings|array<string>|array<string, 'asc'|'desc'>|array<array{string, 'asc'|'desc'}> $orderBy
     */
    public function __construct(Search $search, Orderings|array $orderBy)
    {
        $this->search = clone $search;
        $this->totalCount = null;

        $this->apply(null);

        parent::__construct([], $orderBy);
    }

    public function count(): int
    {
        if ($this->totalCount === null) {
            $this->totalCount = $this->search->count();
        }

        return $this->totalCount;
    }

    public function next(): void
    {
        parent::next();

        $this->current = null;
        $this->currentElement = parent::current();
    }

    public function rewind(): void
    {
        parent::rewind();

        $this->current = null;
        $this->currentElement = parent::current();
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
        $search = clone $this->search;

        $sort = [];

        foreach ($this->orderBy as [$field, $direction]) {
            assert(is_string($field));
            assert(is_string($direction));
            $sort[$field] = $direction;
        }

        $query = new Query\BoolQuery();
        $searchQuery = $search->getQuery();
        if ($searchQuery->hasParam('query')) {
            $filter = $searchQuery->getQuery();
            assert($filter instanceof Query\AbstractQuery);

            $query->addFilter($filter);
        }

        $limit = $this->pageSize;
        if ($this->currentPage instanceof PageToken) {
            $timestamp = $this->currentPage->getOrderValue();
            $limit += $this->currentPage->getOffset();
            $mainOrder = $this->orderBy[0];

            $documentManager = $this->search->getDocumentManager();

            /** @phpstan-var class-string $documentClass */
            $documentClass = $this->search->getDocumentClass();
            assert(is_string($documentClass));

            $typeName = $documentManager->getClassMetadata($documentClass)->getTypeOfField($mainOrder[0]);
            if ($typeName === null) {
                throw new InvalidArgumentException(sprintf('Field %s does not exist or is not a valid field', $mainOrder[0]));
            }

            $type = $documentManager->getTypeManager()->getType($typeName);
            if ($type instanceof AbstractDateTimeType) {
                $datetime = DateTimeImmutable::createFromFormat('U', (string) $timestamp);
                if ($datetime !== false) {
                    $timestamp = $datetime->format('Y-m-d\TH:i:sO');
                }
            }

            $direction = $mainOrder[1] === Orderings::SORT_ASC ? 'gte' : 'lte';

            $query->addFilter(new Query\Range($mainOrder[0], [$direction => $timestamp]));
        } elseif ($this->currentPage instanceof PageNumber) {
            $offset = ($this->currentPage->getPageNumber() - 1) * $limit;
            $search->setOffset($offset);
        } elseif ($this->currentPage instanceof PageOffset) {
            $offset = $this->currentPage->getOffset();
            $search->setOffset($offset);
        }

        $search
            ->setQuery(new Query($query))
            ->setSort($sort)
            ->setLimit($limit);

        return $search->execute();
    }
}
