<?php

declare(strict_types=1);

namespace Solido\Pagination\Elastica;

use Elastica\Query;
use Refugis\DoctrineExtra\IteratorTrait;
use Refugis\DoctrineExtra\ObjectIteratorInterface;
use Refugis\ODM\Elastica\Search\Search;
use Refugis\ODM\Elastica\Type\AbstractDateTimeType;
use Safe\DateTimeImmutable;
use Solido\Pagination\Orderings;
use Solido\Pagination\PagerIterator as BaseIterator;

final class PagerIterator extends BaseIterator implements ObjectIteratorInterface
{
    use IteratorTrait;

    private Search $search;
    private ?int $totalCount;

    /**
     * @param Orderings|string[]|string[][] $orderBy
     *
     * @phpstan-param Orderings|array<string>|array<string, 'asc'|'desc'>|array<array{string, 'asc'|'desc'}> $orderBy
     */
    public function __construct(Search $search, $orderBy)
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
    protected function getObjects(): array
    {
        $search = clone $this->search;

        $sort = [];
        foreach ($this->orderBy as [$field, $direction]) {
            $sort[$field] = $direction;
        }

        $query = new Query\BoolQuery();
        $searchQuery = $search->getQuery();
        if ($searchQuery->hasParam('query')) {
            $query->addFilter($searchQuery->getQuery());
        }

        $limit = $this->pageSize;
        if ($this->token !== null) {
            $timestamp = $this->token->getOrderValue();
            $limit += $this->token->getOffset();
            $mainOrder = $this->orderBy[0];

            $documentManager = $this->search->getDocumentManager();

            $type = $documentManager->getTypeManager()
                ->getType($documentManager->getClassMetadata($this->search->getDocumentClass())->getTypeOfField($mainOrder[0]));

            if ($type instanceof AbstractDateTimeType) {
                $datetime = DateTimeImmutable::createFromFormat('U', (string) $timestamp);
                $timestamp = $datetime->format('Y-m-d\TH:i:sO');
            }

            $direction = $mainOrder[1] === Orderings::SORT_ASC ? 'gte' : 'lte';

            $query->addFilter(new Query\Range($mainOrder[0], [$direction => $timestamp]));
        }

        $search
            ->setQuery(new Query($query))
            ->setSort($sort)
            ->setLimit($limit);

        return $search->execute();
    }
}
