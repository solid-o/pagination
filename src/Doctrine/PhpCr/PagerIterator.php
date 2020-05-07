<?php

declare(strict_types=1);

namespace Solido\Pagination\Doctrine\PhpCr;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ODM\PHPCR\DocumentManagerInterface;
use Doctrine\ODM\PHPCR\Query\Builder\AbstractNode;
use Doctrine\ODM\PHPCR\Query\Builder\ConverterPhpcr;
use Doctrine\ODM\PHPCR\Query\Builder\From;
use Doctrine\ODM\PHPCR\Query\Builder\OperandFactory;
use Doctrine\ODM\PHPCR\Query\Builder\Ordering;
use Doctrine\ODM\PHPCR\Query\Builder\QueryBuilder;
use Doctrine\ODM\PHPCR\Query\Builder\SourceDocument;
use ReflectionMethod;
use Refugis\DoctrineExtra\ObjectIteratorInterface;
use Refugis\DoctrineExtra\ODM\PhpCr\IteratorTrait;
use Solido\Pagination\Orderings;
use Solido\Pagination\PagerIterator as BaseIterator;
use function array_values;
use function assert;
use function is_array;
use function iterator_to_array;

final class PagerIterator extends BaseIterator implements ObjectIteratorInterface
{
    use IteratorTrait;

    /**
     * @param Orderings|string[]|string[][] $orderBy
     *
     * @phpstan-param Orderings|array<string>|array<string, 'asc'|'desc'>|array<array{string, 'asc'|'desc'}> $orderBy
     */
    public function __construct(QueryBuilder $searchable, $orderBy)
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
    protected function getObjects(): array
    {
        $queryBuilder = clone $this->queryBuilder;

        $fromNode = $queryBuilder->getChildOfType(AbstractNode::NT_FROM);
        assert($fromNode instanceof From);
        $source = $fromNode->getChildOfType(AbstractNode::NT_SOURCE);
        assert($source instanceof SourceDocument);
        $alias = $source->getAlias();

        $method = new ReflectionMethod(QueryBuilder::class, 'getConverter');
        $method->setAccessible(true);
        $converter = $method->invoke($queryBuilder);

        $documentManager = (function (): DocumentManagerInterface {
            // @phpstan-ignore-next-line
            return $this->dm;
        })->bindTo($converter, ConverterPhpcr::class)();

        assert($documentManager instanceof DocumentManagerInterface);
        $classMetadata = $documentManager->getClassMetadata($source->getDocumentFqn());

        foreach ($this->orderBy as $key => [$field, $direction]) {
            $method = $key === 0 ? 'orderBy' : 'addOrderBy';

            if ($classMetadata->getTypeOfField($field) === 'nodename') {
                $queryBuilder->{$method}()->{$direction}()->localName($alias);
            } else {
                $queryBuilder->{$method}()->{$direction}()->field($alias . '.' . $field);
            }
        }

        $limit = $this->pageSize;
        if ($this->token !== null) {
            $timestamp = $this->token->getOrderValue();
            $limit += $this->token->getOffset();
            $mainOrder = $this->orderBy[0];

            $type = $documentManager->getClassMetadata($source->getDocumentFqn())->getTypeOfField($mainOrder[0]);
            if ($type === 'date') {
                $timestamp = DateTimeImmutable::createFromFormat('U', (string) $timestamp);
            }

            $direction = $mainOrder[1] === Orderings::SORT_ASC ? 'gte' : 'lte';
            $ordering = $queryBuilder->andWhere()->{$direction}();
            assert($ordering instanceof Ordering);

            if ($classMetadata->getTypeOfField($mainOrder[0]) === 'nodename') {
                $factory = $ordering->localName($alias);
            } else {
                $factory = $ordering->field($alias . '.' . $mainOrder[0]);
            }

            assert($factory instanceof OperandFactory);
            $factory->literal($timestamp);
        }

        $queryBuilder->setMaxResults($limit);

        // phpcs:disable SlevomatCodingStandard.PHP.RequireExplicitAssertion.RequiredExplicitAssertion
        /** @var array|ArrayCollection $result */
        $result = $queryBuilder->getQuery()->getResult();
        // phpcs:enable

        return is_array($result) ? array_values($result) : iterator_to_array($result, false);
    }
}
