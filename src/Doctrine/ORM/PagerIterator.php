<?php

declare(strict_types=1);

namespace Solido\Pagination\Doctrine\ORM;

use DateTimeImmutable;
use Doctrine\DBAL\Types\DateTimeType;
use Doctrine\DBAL\Types\DateTimeTzType;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use Refugis\DoctrineExtra\ObjectIteratorInterface;
use Refugis\DoctrineExtra\ORM\IteratorTrait;
use RuntimeException;
use Solido\Pagination\Orderings;
use Solido\Pagination\PageNumber;
use Solido\Pagination\PageOffset;
use Solido\Pagination\PagerIterator as BaseIterator;
use Solido\Pagination\PageToken;
use TypeError;

use function array_shift;
use function array_unshift;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_string;
use function iterator_to_array;
use function Safe\sprintf;
use function strpos;
use function strtoupper;
use function var_export;

final class PagerIterator extends BaseIterator implements ObjectIteratorInterface
{
    public const FETCH_EAGER = 'EAGER';
    public const FETCH_LAZY = 'LAZY';
    use IteratorTrait;

    /**
     * @var array<string, array<string, string>>
     * @phpstan-var array<class-string, array<string, string>>
     */
    private array $fetchModes = [];

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

    /** @phpstan-param class-string $className */
    public function setFetchMode(string $className, string $associationName, string $fetchMode): void
    {
        if ($fetchMode !== self::FETCH_EAGER && $fetchMode !== self::FETCH_LAZY) {
            throw new TypeError(sprintf('Argument #3 (fetchMode) must be one of %s::FETCH_* constants, %s given.', self::class, var_export($fetchMode, true)));
        }

        $this->fetchModes[$className][$associationName] = $fetchMode;
    }

    /**
     * {@inheritDoc}
     */
    protected function filterObjects(array $objects): array
    {
        if ($this->currentPage instanceof PageToken) {
            return parent::filterObjects($objects);
        }

        return $objects;
    }

    /**
     * {@inheritDoc}
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
        if ($this->currentPage instanceof PageToken) {
            if (count($this->orderBy) < 2) {
                throw new RuntimeException('orderBy must have at least 2 "field" => "direction(ASC|DESC)". The first is the reference timestamp, the second is the checksum field.');
            }

            $timestamp = $this->currentPage->getOrderValue();
            $limit += $this->currentPage->getOffset();

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
            $queryBuilder->setParameter('timeLimit', $timestamp, $type?->getName());
        } elseif ($this->currentPage instanceof PageNumber) {
            $offset = ($this->currentPage->getPageNumber() - 1) * $limit;
            $queryBuilder->setFirstResult($offset);
        } elseif ($this->currentPage instanceof PageOffset) {
            $offset = $this->currentPage->getOffset();
            $queryBuilder->setFirstResult($offset);
        }

        $queryBuilder->setMaxResults($limit);
        $query = $queryBuilder->getQuery();

        foreach ($this->fetchModes as $class => $associations) {
            foreach ($associations as $association => $fetchMode) {
                $query->setFetchMode($class, $association, $fetchMode === self::FETCH_EAGER ? ClassMetadata::FETCH_EAGER : ClassMetadata::FETCH_LAZY);
            }
        }

        return $query->getResult();
    }
}
