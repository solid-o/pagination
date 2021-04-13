<?php

declare(strict_types=1);

namespace Solido\Pagination\Tests\Doctrine\ORM;

use Cake\Chronos\Chronos;
use Doctrine\DBAL\Cache\ArrayStatement;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use PDO;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use ReflectionProperty;
use Solido\Pagination\Doctrine\ORM\PagerIterator;
use Solido\Pagination\PageToken;
use Solido\Pagination\Tests\RelatedTestObject;
use Solido\Pagination\Tests\TestObject;
use Solido\TestUtils\Doctrine\ORM\EntityManagerTrait;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;

use function iterator_to_array;

class PagerIteratorTest extends TestCase
{
    use EntityManagerTrait;

    private QueryBuilder $queryBuilder;
    private PagerIterator $iterator;

    protected function setUp(): void
    {
        $this->_entityManager = null;
        $metadata = new ClassMetadata(RelatedTestObject::class);
        $this->getEntityManager()->getMetadataFactory()->setMetadataFor(RelatedTestObject::class, $metadata);
        $metadata->identifier = ['id'];
        $metadata->mapField([
            'fieldName' => 'id',
            'type' => 'guid',
            'scale' => null,
            'length' => null,
            'unique' => true,
            'nullable' => false,
            'precision' => null,
        ]);
        $metadata->reflFields['id'] = new ReflectionProperty(TestObject::class, 'id');

        $metadata = new ClassMetadata(TestObject::class);
        $this->getEntityManager()->getMetadataFactory()->setMetadataFor(TestObject::class, $metadata);

        $metadata->identifier = ['id'];
        $metadata->mapField([
            'fieldName' => 'id',
            'type' => 'guid',
            'scale' => null,
            'length' => null,
            'unique' => true,
            'nullable' => false,
            'precision' => null,
        ]);

        $metadata->mapField([
            'fieldName' => 'timestamp',
            'type' => 'datetime_immutable',
            'scale' => null,
            'length' => null,
            'unique' => true,
            'nullable' => false,
            'precision' => null,
        ]);

        $metadata->mapManyToOne([
            'fieldName' => 'related',
            'targetEntity' => RelatedTestObject::class,
        ]);

        $metadata->reflFields['id'] = new ReflectionProperty(TestObject::class, 'id');
        $metadata->reflFields['timestamp'] = new ReflectionProperty(TestObject::class, 'timestamp');
        $metadata->reflFields['related'] = new ReflectionProperty(TestObject::class, 'related');

        $this->queryBuilder = $this->getEntityManager()->createQueryBuilder();
        $this->queryBuilder->select('a')
            ->from(TestObject::class, 'a');

        $this->_innerConnection->query('')->shouldNotBeCalled();

        $this->iterator = new PagerIterator($this->queryBuilder, ['timestamp', 'id']);
        $this->iterator->setPageSize(3);
    }

    public function testPagerShouldGenerateFirstPageWithToken(): void
    {
        $this->_innerConnection->query('SELECT t0_.id AS id_0, t0_.timestamp AS timestamp_1, t0_.related_id AS related_id_2 FROM TestObject t0_ ORDER BY t0_.timestamp ASC, t0_.id ASC LIMIT 3')
            ->willReturn(new ArrayStatement([
                ['id_0' => 'b4902bde-28d2-4ff9-8971-8bfeb3e943c1', 'timestamp_1' => '1991-11-24 00:00:00', 'related_id_2' => null],
                ['id_0' => '191a54d8-990c-4ea7-9a23-0aed29d1fffe', 'timestamp_1' => '1991-11-24 01:00:00', 'related_id_2' => null],
                ['id_0' => '9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed', 'timestamp_1' => '1991-11-24 02:00:00', 'related_id_2' => null],
            ]));

        $request = $this->prophesize(Request::class);
        $request->query = new ParameterBag([]);

        $this->iterator->setToken(PageToken::fromRequest($request->reveal()));

        self::assertEquals([
            new TestObject('b4902bde-28d2-4ff9-8971-8bfeb3e943c1', new Chronos('1991-11-24 00:00:00')),
            new TestObject('191a54d8-990c-4ea7-9a23-0aed29d1fffe', new Chronos('1991-11-24 01:00:00')),
            new TestObject('9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed', new Chronos('1991-11-24 02:00:00')),
        ], iterator_to_array($this->iterator));

        self::assertEquals('bfdew0_1_1jvdwz4', (string) $this->iterator->getNextPageToken());
    }

    public function testPagerShouldGenerateSecondPageWithTokenAndLastPage(): void
    {
        $this->_innerConnection->prepare('SELECT t0_.id AS id_0, t0_.timestamp AS timestamp_1, t0_.related_id AS related_id_2 FROM TestObject t0_ WHERE t0_.timestamp >= ? ORDER BY t0_.timestamp ASC, t0_.id ASC LIMIT 4')
            ->willReturn($stmt = $this->prophesize(Statement::class));

        $stmt->bindValue(1, '1991-11-24 02:00:00', PDO::PARAM_STR)->willReturn();
        $stmt->setFetchMode(PDO::FETCH_ASSOC)->willReturn();
        $stmt->execute()->willReturn(true);
        $stmt->closeCursor()->willReturn(true);

        $stmt->fetch(PDO::FETCH_ASSOC, Argument::cetera())
            ->willReturn(
                ['id_0' => '9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed', 'timestamp_1' => '1991-11-24 02:00:00', 'related_id_2' => null],
                ['id_0' => 'af6394a4-7344-4fe8-9748-e6c67eba5ade', 'timestamp_1' => '1991-11-24 03:00:00', 'related_id_2' => null],
                ['id_0' => '84810e2e-448f-4f58-acb8-4db1381f5de3', 'timestamp_1' => '1991-11-24 04:00:00', 'related_id_2' => null],
                ['id_0' => 'eadd7470-95f5-47e8-8e74-083d45c307f6', 'timestamp_1' => '1991-11-24 05:00:00', 'related_id_2' => null],
                false
            );

        $request = $this->prophesize(Request::class);
        $request->query = new ParameterBag(['continue' => 'bfdew0_1_1jvdwz4']);

        $this->iterator->setToken(PageToken::fromRequest($request->reveal()));

        self::assertEquals([
            new TestObject('af6394a4-7344-4fe8-9748-e6c67eba5ade', new Chronos('1991-11-24 03:00:00')),
            new TestObject('84810e2e-448f-4f58-acb8-4db1381f5de3', new Chronos('1991-11-24 04:00:00')),
            new TestObject('eadd7470-95f5-47e8-8e74-083d45c307f6', new Chronos('1991-11-24 05:00:00')),
        ], iterator_to_array($this->iterator));

        self::assertEquals('bfdn80_1_cukvcs', (string) $this->iterator->getNextPageToken());
    }

    public function testOffsetShouldWork(): void
    {
        $this->_innerConnection->query('SELECT t0_.id AS id_0, t0_.timestamp AS timestamp_1, t0_.related_id AS related_id_2 FROM TestObject t0_ ORDER BY t0_.timestamp ASC, t0_.id ASC LIMIT 3')
            ->willReturn(new ArrayStatement([
                ['id_0' => 'b4902bde-28d2-4ff9-8971-8bfeb3e943c1', 'timestamp_1' => '1991-11-24 00:00:00', 'related_id_2' => null],
                ['id_0' => '191a54d8-990c-4ea7-9a23-0aed29d1fffe', 'timestamp_1' => '1991-11-24 01:00:00', 'related_id_2' => null],
                ['id_0' => '84810e2e-448f-4f58-acb8-4db1381f5de3', 'timestamp_1' => '1991-11-24 01:00:00', 'related_id_2' => null],
            ]));

        $request = $this->prophesize(Request::class);
        $request->query = new ParameterBag([]);

        $this->iterator->setToken(PageToken::fromRequest($request->reveal()));

        self::assertEquals([
            new TestObject('b4902bde-28d2-4ff9-8971-8bfeb3e943c1', new Chronos('1991-11-24 00:00:00')),
            new TestObject('191a54d8-990c-4ea7-9a23-0aed29d1fffe', new Chronos('1991-11-24 01:00:00')),
            new TestObject('84810e2e-448f-4f58-acb8-4db1381f5de3', new Chronos('1991-11-24 01:00:00')),
        ], iterator_to_array($this->iterator));

        self::assertEquals(2, $this->iterator->getNextPageToken()->getOffset());

        $request = $this->prophesize(Request::class);
        $request->query = new ParameterBag(['continue' => 'bfdc40_2_hzr9o9']);

        $this->iterator->setToken(PageToken::fromRequest($request->reveal()));

        $this->_innerConnection->prepare('SELECT t0_.id AS id_0, t0_.timestamp AS timestamp_1, t0_.related_id AS related_id_2 FROM TestObject t0_ WHERE t0_.timestamp >= ? ORDER BY t0_.timestamp ASC, t0_.id ASC LIMIT 5')
            ->willReturn($stmt = $this->prophesize(Statement::class));

        $stmt->bindValue(1, '1991-11-24 01:00:00', PDO::PARAM_STR)->willReturn();
        $stmt->setFetchMode(PDO::FETCH_ASSOC)->willReturn();
        $stmt->execute()->willReturn(true);
        $stmt->closeCursor()->willReturn(true);

        $stmt->fetch(PDO::FETCH_ASSOC, Argument::cetera())
            ->willReturn(
                ['id_0' => '191a54d8-990c-4ea7-9a23-0aed29d1fffe', 'timestamp_1' => '1991-11-24 01:00:00', 'related_id_2' => null],
                ['id_0' => '84810e2e-448f-4f58-acb8-4db1381f5de3', 'timestamp_1' => '1991-11-24 01:00:00', 'related_id_2' => null],
                ['id_0' => '9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed', 'timestamp_1' => '1991-11-24 01:00:00', 'related_id_2' => null],
                ['id_0' => 'af6394a4-7344-4fe8-9748-e6c67eba5ade', 'timestamp_1' => '1991-11-24 01:00:00', 'related_id_2' => null],
                ['id_0' => 'eadd7470-95f5-47e8-8e74-083d45c307f6', 'timestamp_1' => '1991-11-24 02:00:00', 'related_id_2' => null],
                false
            );

        self::assertEquals([
            new TestObject('9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed', new Chronos('1991-11-24 01:00:00')),
            new TestObject('af6394a4-7344-4fe8-9748-e6c67eba5ade', new Chronos('1991-11-24 01:00:00')),
            new TestObject('eadd7470-95f5-47e8-8e74-083d45c307f6', new Chronos('1991-11-24 02:00:00')),
        ], iterator_to_array($this->iterator));
    }

    public function testPagerShouldReturnFirstPageWithTimestampDifference(): void
    {
        $this->_innerConnection->prepare('SELECT t0_.id AS id_0, t0_.timestamp AS timestamp_1, t0_.related_id AS related_id_2 FROM TestObject t0_ WHERE t0_.timestamp >= ? ORDER BY t0_.timestamp ASC, t0_.id ASC LIMIT 4')
            ->willReturn($stmt = $this->prophesize(Statement::class));

        $stmt->bindValue(1, '1991-11-24 02:00:00', PDO::PARAM_STR)->willReturn();
        $stmt->setFetchMode(PDO::FETCH_ASSOC)->willReturn();
        $stmt->execute()->willReturn(true);
        $stmt->closeCursor()->willReturn(true);

        $stmt->fetch(PDO::FETCH_ASSOC, Argument::cetera())
            ->willReturn(
                ['id_0' => '9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed', 'timestamp_1' => '1991-11-24 02:30:00', 'related_id_2' => null],
                ['id_0' => 'af6394a4-7344-4fe8-9748-e6c67eba5ade', 'timestamp_1' => '1991-11-24 03:00:00', 'related_id_2' => null],
                ['id_0' => '84810e2e-448f-4f58-acb8-4db1381f5de3', 'timestamp_1' => '1991-11-24 04:00:00', 'related_id_2' => null],
                ['id_0' => 'eadd7470-95f5-47e8-8e74-083d45c307f6', 'timestamp_1' => '1991-11-24 05:00:00', 'related_id_2' => null],
                false
            );

        $request = $this->prophesize(Request::class);
        $request->query = new ParameterBag(['continue' => 'bfdew0_1_1jvdwz4']); // This token represents a request with the 02:00:00 timestamp

        $this->iterator->setToken(PageToken::fromRequest($request->reveal()));

        self::assertEquals([
            new TestObject('9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed', new Chronos('1991-11-24 02:30:00')),
            new TestObject('af6394a4-7344-4fe8-9748-e6c67eba5ade', new Chronos('1991-11-24 03:00:00')),
            new TestObject('84810e2e-448f-4f58-acb8-4db1381f5de3', new Chronos('1991-11-24 04:00:00')),
        ], iterator_to_array($this->iterator));

        self::assertEquals('bfdkg0_1_1xirtcr', (string) $this->iterator->getNextPageToken());
    }

    public function testPagerShouldReturnFirstPageWithChecksumDifference(): void
    {
        $this->_innerConnection->prepare('SELECT t0_.id AS id_0, t0_.timestamp AS timestamp_1, t0_.related_id AS related_id_2 FROM TestObject t0_ WHERE t0_.timestamp >= ? ORDER BY t0_.timestamp ASC, t0_.id ASC LIMIT 4')
            ->willReturn($stmt = $this->prophesize(Statement::class));

        $stmt->bindValue(1, '1991-11-24 02:00:00', PDO::PARAM_STR)->willReturn();
        $stmt->setFetchMode(PDO::FETCH_ASSOC)->willReturn();
        $stmt->execute()->willReturn(true);
        $stmt->closeCursor()->willReturn(true);

        $stmt->fetch(PDO::FETCH_ASSOC, Argument::cetera())
            ->willReturn(
                ['id_0' => 'af6394a4-7344-4fe8-9748-e6c67eba5ade', 'timestamp_1' => '1991-11-24 02:00:00', 'related_id_2' => null],
                ['id_0' => '9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed', 'timestamp_1' => '1991-11-24 03:00:00', 'related_id_2' => null],
                ['id_0' => '191a54d8-990c-4ea7-9a23-0aed29d1fffe', 'timestamp_1' => '1991-11-24 04:00:00', 'related_id_2' => null],
                ['id_0' => 'eadd7470-95f5-47e8-8e74-083d45c307f6', 'timestamp_1' => '1991-11-24 05:00:00', 'related_id_2' => null],
                false
            );

        $request = $this->prophesize(Request::class);
        $request->query = new ParameterBag(['continue' => 'bfdew0_1_1jvdwz4']); // This token represents a request with the 02:00:00 timestamp

        $this->iterator->setToken(PageToken::fromRequest($request->reveal()));

        self::assertEquals([
            new TestObject('af6394a4-7344-4fe8-9748-e6c67eba5ade', new Chronos('1991-11-24 02:00:00')),
            new TestObject('9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed', new Chronos('1991-11-24 03:00:00')),
            new TestObject('191a54d8-990c-4ea7-9a23-0aed29d1fffe', new Chronos('1991-11-24 04:00:00')),
        ], iterator_to_array($this->iterator));

        self::assertEquals('bfdkg0_1_7gqxdp', (string) $this->iterator->getNextPageToken());
    }

    public function testPagerShouldOrderByRelatedField(): void
    {
        $this->iterator = new PagerIterator($this->queryBuilder, ['related.id', 'id']);
        $this->iterator->setPageSize(3);

        $this->_innerConnection->query('SELECT t0_.id AS id_0, t0_.timestamp AS timestamp_1, t0_.related_id AS related_id_2 FROM TestObject t0_ LEFT JOIN RelatedTestObject r1_ ON t0_.related_id = r1_.id ORDER BY r1_.id ASC, t0_.id ASC LIMIT 3')
            ->willReturn($stmt = $this->prophesize(Statement::class));

        $stmt->setFetchMode(PDO::FETCH_ASSOC)->willReturn();
        $stmt->execute()->willReturn(true);
        $stmt->closeCursor()->willReturn(true);

        $stmt->fetch(PDO::FETCH_ASSOC, Argument::cetera())
            ->willReturn(
                ['id_0' => 'af6394a4-7344-4fe8-9748-e6c67eba5ade', 'timestamp_1' => '1991-11-24 02:00:00', 'related_id_2' => '1'],
                ['id_0' => '9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed', 'timestamp_1' => '1991-11-24 03:00:00', 'related_id_2' => '2'],
                ['id_0' => '191a54d8-990c-4ea7-9a23-0aed29d1fffe', 'timestamp_1' => '1991-11-24 04:00:00', 'related_id_2' => '3'],
                ['id_0' => 'eadd7470-95f5-47e8-8e74-083d45c307f6', 'timestamp_1' => '1991-11-24 05:00:00', 'related_id_2' => '4'],
                false
            );

        $objects = iterator_to_array($this->iterator);
        self::assertEquals([
            'af6394a4-7344-4fe8-9748-e6c67eba5ade',
            '9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed',
            '191a54d8-990c-4ea7-9a23-0aed29d1fffe'
        ], array_column($objects, 'id'));

        self::assertEquals('3_1_7gqxdp', (string) $this->iterator->getNextPageToken());
    }
}
