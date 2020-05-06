<?php

declare(strict_types=1);

namespace Solido\Pagination\Doctrine\ORM;

use DateTimeImmutable;
use Doctrine\DBAL\Types\DateTimeType;
use Doctrine\DBAL\Types\DateTimeTzType;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\QueryBuilder;
use Refugis\DoctrineExtra\ObjectIteratorInterface;
use Refugis\DoctrineExtra\ORM\IteratorTrait;
use Solido\Pagination\Orderings;
use Solido\Pagination\PagerIterator as BaseIterator;
use function is_string;
use function strtoupper;

final class PagerIterator extends BaseIterator implements ObjectIteratorInterface
{
    use IteratorTrait;

    /**
     * @param Orderings|array<string>|array<string, string>|array<array<string>> $orderBy
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
        $alias = $queryBuilder->getRootAliases()[0];

        foreach ($this->orderBy as $key => [$field, $direction]) {
            $method = $key === 0 ? 'orderBy' : 'addOrderBy';
            $queryBuilder->{$method}($alias . '.' . $field, strtoupper($direction));
        }

        $limit = $this->pageSize;
        if ($this->token !== null) {
            $timestamp = $this->token->getOrderValue();
            $limit += $this->token->getOffset();
            $mainOrder = $this->orderBy[0];

            $type = $queryBuilder->getEntityManager()
                ->getClassMetadata($queryBuilder->getRootEntities()[0])
                ->getTypeOfField($mainOrder[0]);

            if (is_string($type)) {
                $type = Type::getType($type);
            }

            if ($type instanceof DateTimeType || $type instanceof DateTimeTzType) {
                $timestamp = DateTimeImmutable::createFromFormat('U', (string) $timestamp);
            }

            $direction = $mainOrder[1] === Orderings::SORT_ASC ? '>=' : '<=';
            $queryBuilder->andWhere($alias . '.' . $mainOrder[0] . ' ' . $direction . ' :timeLimit');
            $queryBuilder->setParameter('timeLimit', $timestamp);
        }

        $queryBuilder->setMaxResults($limit);

        return $queryBuilder->getQuery()->getResult();
    }
}
