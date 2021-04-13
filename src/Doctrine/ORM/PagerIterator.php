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

use function array_shift;
use function array_unshift;
use function explode;
use function implode;
use function in_array;
use function is_string;
use function iterator_to_array;
use function strpos;
use function strtoupper;

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
        $orderClass = [];
        $mainOrder = iterator_to_array($this->orderBy, true);

        foreach ($this->orderBy as $key => [$field, $direction]) {
            $alias = $queryBuilder->getRootAliases()[0];
            $orderClass[$key] = $queryBuilder->getRootEntities()[0];
            $method = $key === 0 ? 'orderBy' : 'addOrderBy';
            if (strpos($field, '.') !== false) {
                $em = $queryBuilder->getEntityManager();
                $metadata = $em->getClassMetadata($orderClass[$key]);
                $aliases = $queryBuilder->getAllAliases();

                $associations = explode('.', $field);
                while ($association = array_shift($associations)) {
                    if (! isset($metadata->associationMappings[$association])) {
                        // Not an association
                        array_unshift($associations, $association);
                        $field = implode('.', $associations);
                        break;
                    }

                    if (! in_array($association, $aliases, true)) {
                        $queryBuilder->leftJoin($alias . '.' . $association, $association);
                    }

                    $orderClass[$key] = $metadata->associationMappings[$association]['targetEntity'];
                    $metadata = $em->getClassMetadata($orderClass[$key]);
                    $alias = $association;
                }
            }

            $mainOrder[$key][0] = $alias . '.' . $field;
            $queryBuilder->{$method}($alias . '.' . $field, strtoupper($direction));
        }

        $limit = $this->pageSize;
        if ($this->token !== null) {
            $timestamp = $this->token->getOrderValue();
            $limit += $this->token->getOffset();

            $type = $queryBuilder->getEntityManager()
                ->getClassMetadata($orderClass[0])
                ->getTypeOfField(explode('.', $mainOrder[0][0], 2)[1]);

            if (is_string($type)) {
                $type = Type::getType($type);
            }

            if ($type instanceof DateTimeType || $type instanceof DateTimeTzType) {
                $timestamp = DateTimeImmutable::createFromFormat('U', (string) $timestamp);
            }

            $direction = $mainOrder[0][1] === Orderings::SORT_ASC ? '>=' : '<=';
            $queryBuilder->andWhere($mainOrder[0][0] . ' ' . $direction . ' :timeLimit');
            $queryBuilder->setParameter('timeLimit', $timestamp, $type !== null ? $type->getName() : null);
        }

        $queryBuilder->setMaxResults($limit);

        return $queryBuilder->getQuery()->getResult();
    }
}
