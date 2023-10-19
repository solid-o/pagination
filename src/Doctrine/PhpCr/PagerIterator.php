<?php

declare(strict_types=1);

namespace Solido\Pagination\Doctrine\PhpCr;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ODM\PHPCR\DocumentManagerInterface;
use Doctrine\ODM\PHPCR\Query\Builder\AbstractNode;
use Doctrine\ODM\PHPCR\Query\Builder\ConstraintComparison;
use Doctrine\ODM\PHPCR\Query\Builder\ConverterPhpcr;
use Doctrine\ODM\PHPCR\Query\Builder\From;
use Doctrine\ODM\PHPCR\Query\Builder\OperandFactory;
use Doctrine\ODM\PHPCR\Query\Builder\QueryBuilder;
use Doctrine\ODM\PHPCR\Query\Builder\SourceDocument;
use Doctrine\ODM\PHPCR\Query\Builder\SourceJoin;
use ReflectionMethod;
use Refugis\DoctrineExtra\ObjectIteratorInterface;
use Refugis\DoctrineExtra\ODM\PhpCr\IteratorTrait;
use RuntimeException;
use Solido\Pagination\Orderings;
use Solido\Pagination\PageNumber;
use Solido\Pagination\PageOffset;
use Solido\Pagination\PagerIterator as BaseIterator;
use Solido\Pagination\PageToken;

use function array_values;
use function assert;
use function count;
use function is_array;
use function is_string;
use function iterator_to_array;

final class PagerIterator extends BaseIterator implements ObjectIteratorInterface
{
    use IteratorTrait;

    /**
     * @param Orderings|string[]|string[][] $orderBy
     * @phpstan-param Orderings|array<string>|array<string, 'asc'|'desc'>|array<array{string, 'asc'|'desc'}> $orderBy
     */
    public function __construct(QueryBuilder $searchable, Orderings|array $orderBy = new Orderings([]))
    {
        $this->queryBuilder = clone $searchable;
        $this->totalCount = null;

        $this->apply(null);

        parent::__construct([], $orderBy);
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
        $queryBuilder = clone $this->queryBuilder;

        $fromNode = $queryBuilder->getChildOfType(AbstractNode::NT_FROM);
        assert($fromNode instanceof From);
        $source = $fromNode->getChildOfType(AbstractNode::NT_SOURCE);
        if ($source instanceof SourceJoin) {
            $source = $source
                ->getChildOfType(AbstractNode::NT_SOURCE_JOIN_LEFT)
                ->getChildOfType(AbstractNode::NT_SOURCE);
        }

        assert($source instanceof SourceDocument);
        $alias = $source->getAlias();

        $method = new ReflectionMethod(QueryBuilder::class, 'getConverter');
        $method->setAccessible(true);
        $converter = $method->invoke($queryBuilder);

        $documentManager = (function (): DocumentManagerInterface {
            // @phpstan-ignore-next-line
            return $this->dm;
        })->bindTo($converter, ConverterPhpcr::class)();

        /** @phpstan-var class-string $documentFqn */
        $documentFqn = $source->getDocumentFqn();
        assert(is_string($documentFqn));

        assert($documentManager instanceof DocumentManagerInterface);
        $classMetadata = $documentManager->getClassMetadata($documentFqn);

        foreach ($this->orderBy as $key => [$field, $direction]) {
            $method = $key === 0 ? 'orderBy' : 'addOrderBy';

            if ($classMetadata->getTypeOfField($field) === 'nodename') {
                $queryBuilder->{$method}()->{$direction}()->localName($alias);
            } else {
                $queryBuilder->{$method}()->{$direction}()->field($alias . '.' . $field);
            }
        }

        $limit = $this->pageSize;
        if ($this->currentPage instanceof PageToken) {
            if (count($this->orderBy) < 2) {
                throw new RuntimeException('orderBy must have at least 2 "field" => "direction(ASC|DESC)". The first is the reference timestamp, the second is the checksum field.');
            }

            $timestamp = $this->currentPage->getOrderValue();
            $limit += $this->currentPage->getOffset();
            $mainOrder = $this->orderBy[0];

            /** @phpstan-var class-string $sourceFqn */
            $sourceFqn = $source->getDocumentFqn();
            assert(is_string($sourceFqn));

            $type = $documentManager->getClassMetadata($sourceFqn)->getTypeOfField($mainOrder[0]);
            if ($type === 'date') {
                $timestamp = DateTimeImmutable::createFromFormat('U', (string) $timestamp);
            }

            $direction = $mainOrder[1] === Orderings::SORT_ASC ? 'gte' : 'lte';
            $ordering = $queryBuilder->andWhere()->{$direction}();
            assert($ordering instanceof ConstraintComparison);

            if ($classMetadata->getTypeOfField($mainOrder[0]) === 'nodename') {
                $factory = $ordering->localName($alias);
            } else {
                $factory = $ordering->field($alias . '.' . $mainOrder[0]);
            }

            assert($factory instanceof OperandFactory);
            $factory->literal($timestamp);
        } elseif ($this->currentPage instanceof PageNumber) {
            $offset = ($this->currentPage->getPageNumber() - 1) * $limit;
            $queryBuilder->setFirstResult($offset);
        } elseif ($this->currentPage instanceof PageOffset) {
            $offset = $this->currentPage->getOffset();
            $queryBuilder->setFirstResult($offset);
        }

        $queryBuilder->setMaxResults($limit);

        // phpcs:disable SlevomatCodingStandard.PHP.RequireExplicitAssertion.RequiredExplicitAssertion
        /** @var array|ArrayCollection $result */
        $result = $queryBuilder->getQuery()->getResult();
        // phpcs:enable

        return is_array($result) ? array_values($result) : iterator_to_array($result, false);
    }
}
