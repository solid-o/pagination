<?php

declare(strict_types=1);

namespace Solido\Pagination\Doctrine\DBAL;

use Doctrine\DBAL\Driver\ResultStatement;
use Doctrine\DBAL\FetchMode;
use Doctrine\DBAL\Query\QueryBuilder;
use Refugis\DoctrineExtra\DBAL\IteratorTrait;
use Refugis\DoctrineExtra\ObjectIteratorInterface;
use Solido\Pagination\Orderings;
use Solido\Pagination\PagerIterator as BaseIterator;
use function assert;
use function call_user_func;
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
     * @param Orderings|array<string>|array<string, string>|array<array<string>> $orderBy
     */
    public function __construct(QueryBuilder $queryBuilder, $orderBy)
    {
        $this->queryBuilder = clone $queryBuilder;
        $this->totalCount = null;

        $this->apply(null);

        parent::__construct([], $orderBy);
    }

    /**
     * {@inheritdoc}
     */
    public function current()
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
    protected function getObjects(): array
    {
        $queryBuilder = clone $this->queryBuilder;

        $offset = $queryBuilder->getFirstResult();
        $queryBuilder->setFirstResult(0);

        $queryBuilder = $this->queryBuilder->getConnection()
            ->createQueryBuilder()
            ->select('*')
            ->from('(' . $queryBuilder->getSQL() . ')', 'x')
            ->setFirstResult($offset);

        foreach ($this->orderBy as $key => [$field, $direction]) {
            $method = $key === 0 ? 'orderBy' : 'addOrderBy';
            $queryBuilder->{$method}($field, strtoupper($direction));
        }

        $limit = $this->pageSize;
        if ($this->token !== null) {
            $timestamp = $this->token->getOrderValue();
            $limit += $this->token->getOffset();
            $mainOrder = $this->orderBy[0];

            $direction = $mainOrder[1] === Orderings::SORT_ASC ? '>=' : '<=';
            $queryBuilder->andWhere($mainOrder[0] . ' ' . $direction . ' :timeLimit');
            $queryBuilder->setParameter('timeLimit', $timestamp);
        }

        $queryBuilder->setMaxResults($limit);
        $stmt = $queryBuilder->execute();
        assert($stmt instanceof ResultStatement);

        return $stmt->fetchAll(FetchMode::STANDARD_OBJECT);
    }

    /**
     * @return array<object>
     */
    private static function toArray(object $rowObject): array
    {
        return json_decode(json_encode($rowObject, JSON_THROW_ON_ERROR, 512), true, 512, JSON_THROW_ON_ERROR);
    }
}
