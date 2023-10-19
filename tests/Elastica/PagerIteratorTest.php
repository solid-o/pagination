<?php

declare(strict_types=1);

namespace Solido\Pagination\Tests\Elastica;

use DateTimeImmutable;
use Elastica\Query;
use Elastica\Response;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use ReflectionClass;
use Refugis\ODM\Elastica\Metadata\DocumentMetadata;
use Refugis\ODM\Elastica\Metadata\FieldMetadata;
use Solido\Pagination\Elastica\PagerIterator;
use Solido\Pagination\PageNumber;
use Solido\Pagination\PageOffset;
use Solido\Pagination\PageToken;
use Solido\Pagination\Tests\TestObject;
use Solido\TestUtils\Elastica\DocumentManagerTrait;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;

use function iterator_to_array;

class PagerIteratorTest extends TestCase
{
    use DocumentManagerTrait;

    private PagerIterator $iterator;

    protected function setUp(): void
    {
        $this->documentManager = null;
        $class = new DocumentMetadata(new ReflectionClass(TestObject::class));
        $class->collectionName = 'test-object';

        $id = new FieldMetadata($class, 'id');
        $id->identifier = true;
        $id->type = 'string';
        $id->fieldName = 'id';
        $class->identifier = $id;

        $field = new FieldMetadata($class, 'timestamp');
        $field->type = 'datetime_immutable';
        $field->fieldName = 'timestamp';

        $class->addAttributeMetadata($id);
        $class->addAttributeMetadata($field);

        $documentManager = $this->getDocumentManager();
        $documentManager->getMetadataFactory()->setMetadataFor(TestObject::class, $class);

        $collection = $documentManager->getCollection(TestObject::class);

        $query = new Query();
        $query
            ->setSort(['timestamp', 'id'])
            ->setSize(3);

        $search = $collection->createSearch($documentManager, $query);
        $this->iterator = new PagerIterator($search, ['timestamp', 'id']);
        $this->iterator->setPageSize(3);
    }

    public function testPagerShouldGenerateFirstPageWithToken(): void
    {
        $expectedQuery = [
            'query' => [
                'bool' => (object) [],
            ],
            '_source' => [
                'timestamp',
            ],
            'sort' => [
                ['timestamp' => 'asc'],
                ['id' => 'asc'],
            ],
            'size' => 3,
            'seq_no_primary_term' => true,
            'version' => true,
        ];

        $response = new Response([
            'took' => 1,
            'timed_out' => false,
            '_shards' => [
                'total' => 3,
                'successful' => 3,
                'failed' => 0,
            ],
            'hits' => [
                'total' => 1000,
                'max_score' => 0.0,
                'hits' => [
                    [
                        '_index' => 'test-object',
                        '_type' => 'test-object',
                        '_score' => 1.0,
                        '_id' => 'b4902bde-28d2-4ff9-8971-8bfeb3e943c1',
                        '_source' => [
                            'timestamp' => '1991-11-24 00:00:00',
                        ],
                    ],
                    [
                        '_index' => 'test-object',
                        '_type' => 'test-object',
                        '_score' => 1.0,
                        '_id' => '191a54d8-990c-4ea7-9a23-0aed29d1fffe',
                        '_source' => [
                            'timestamp' => '1991-11-24 01:00:00',
                        ],
                    ],
                    [
                        '_index' => 'test-object',
                        '_type' => 'test-object',
                        '_score' => 1.0,
                        '_id' => '9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed',
                        '_source' => [
                            'timestamp' => '1991-11-24 02:00:00',
                        ],
                    ],
                ],
            ],
        ], 200);

        $this->client->request('test-object/_search', 'POST', $expectedQuery, [])
            ->willReturn($response);

        $request = $this->prophesize(Request::class);
        $request->query = new ParameterBag([]);

        $this->iterator->setCurrentPage(PageToken::fromRequest($request->reveal()));

        self::assertEquals([
            new TestObject('b4902bde-28d2-4ff9-8971-8bfeb3e943c1', new DateTimeImmutable('1991-11-24 00:00:00')),
            new TestObject('191a54d8-990c-4ea7-9a23-0aed29d1fffe', new DateTimeImmutable('1991-11-24 01:00:00')),
            new TestObject('9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed', new DateTimeImmutable('1991-11-24 02:00:00')),
        ], iterator_to_array($this->iterator));

        self::assertEquals('bfdew0_1_1jvdwz4', (string) $this->iterator->getNextPageToken());
    }

    public function testPagerShouldGenerateSecondPageWithTokenAndLastPage(): void
    {
        $expectedQuery = [
            'query' => [
                'bool' => [
                    'filter' => [
                        [
                            'range' => [
                                'timestamp' => ['gte' => '1991-11-24T02:00:00+0000'],
                            ],
                        ],
                    ],
                ],
            ],
            '_source' => [
                'timestamp',
            ],
            'sort' => [
                ['timestamp' => 'asc'],
                ['id' => 'asc'],
            ],
            'size' => 4,
            'seq_no_primary_term' => true,
            'version' => true,
        ];

        $response = new Response([
            'took' => 1,
            'timed_out' => false,
            '_shards' => [
                'total' => 4,
                'successful' => 4,
                'failed' => 0,
            ],
            'hits' => [
                'total' => 1000,
                'max_score' => 0.0,
                'hits' => [
                    [
                        '_index' => 'test-object',
                        '_type' => 'test-object',
                        '_score' => 1.0,
                        '_id' => '9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed',
                        '_source' => [
                            'timestamp' => '1991-11-24 02:00:00',
                        ],
                    ],
                    [
                        '_index' => 'test-object',
                        '_type' => 'test-object',
                        '_score' => 1.0,
                        '_id' => 'af6394a4-7344-4fe8-9748-e6c67eba5ade',
                        '_source' => [
                            'timestamp' => '1991-11-24 03:00:00',
                        ],
                    ],
                    [
                        '_index' => 'test-object',
                        '_type' => 'test-object',
                        '_score' => 1.0,
                        '_id' => '84810e2e-448f-4f58-acb8-4db1381f5de3',
                        '_source' => [
                            'timestamp' => '1991-11-24 04:00:00',
                        ],
                    ],
                    [
                        '_index' => 'test-object',
                        '_type' => 'test-object',
                        '_score' => 1.0,
                        '_id' => 'eadd7470-95f5-47e8-8e74-083d45c307f6',
                        '_source' => [
                            'timestamp' => '1991-11-24 05:00:00',
                        ],
                    ],
                ],
            ],
        ], 200);

        $this->client->request('test-object/_search', 'POST', $expectedQuery, [])
            ->willReturn($response);

        $request = $this->prophesize(Request::class);
        $request->query = new ParameterBag(['continue' => 'bfdew0_1_1jvdwz4']);

        $this->iterator->setCurrentPage(PageToken::fromRequest($request->reveal()));

        self::assertEquals([
            new TestObject('af6394a4-7344-4fe8-9748-e6c67eba5ade', new DateTimeImmutable('1991-11-24 03:00:00')),
            new TestObject('84810e2e-448f-4f58-acb8-4db1381f5de3', new DateTimeImmutable('1991-11-24 04:00:00')),
            new TestObject('eadd7470-95f5-47e8-8e74-083d45c307f6', new DateTimeImmutable('1991-11-24 05:00:00')),
        ], iterator_to_array($this->iterator));

        self::assertEquals('bfdn80_1_cukvcs', (string) $this->iterator->getNextPageToken());
    }

    public function testOffsetShouldWork(): void
    {
        $expectedQuery = [
            'query' => [
                'bool' => (object) [],
            ],
            '_source' => [
                'timestamp',
            ],
            'sort' => [
                ['timestamp' => 'asc'],
                ['id' => 'asc'],
            ],
            'size' => 3,
            'seq_no_primary_term' => true,
            'version' => true,
        ];

        $response = new Response([
            'took' => 1,
            'timed_out' => false,
            '_shards' => [
                'total' => 3,
                'successful' => 3,
                'failed' => 0,
            ],
            'hits' => [
                'total' => 1000,
                'max_score' => 0.0,
                'hits' => [
                    [
                        '_index' => 'test-object',
                        '_type' => 'test-object',
                        '_score' => 1.0,
                        '_id' => 'b4902bde-28d2-4ff9-8971-8bfeb3e943c1',
                        '_source' => [
                            'timestamp' => '1991-11-24 00:00:00',
                        ],
                    ],
                    [
                        '_index' => 'test-object',
                        '_type' => 'test-object',
                        '_score' => 1.0,
                        '_id' => '191a54d8-990c-4ea7-9a23-0aed29d1fffe',
                        '_source' => [
                            'timestamp' => '1991-11-24 01:00:00',
                        ],
                    ],
                    [
                        '_index' => 'test-object',
                        '_type' => 'test-object',
                        '_score' => 1.0,
                        '_id' => '84810e2e-448f-4f58-acb8-4db1381f5de3',
                        '_source' => [
                            'timestamp' => '1991-11-24 01:00:00',
                        ],
                    ],
                ],
            ],
        ], 200);

        $this->client->request('test-object/_search', 'POST', $expectedQuery, [])
            ->willReturn($response);

        $request = $this->prophesize(Request::class);
        $request->query = new ParameterBag([]);

        $this->iterator->setCurrentPage(PageToken::fromRequest($request->reveal()));

        self::assertEquals([
            new TestObject('b4902bde-28d2-4ff9-8971-8bfeb3e943c1', new DateTimeImmutable('1991-11-24 00:00:00')),
            new TestObject('191a54d8-990c-4ea7-9a23-0aed29d1fffe', new DateTimeImmutable('1991-11-24 01:00:00')),
            new TestObject('84810e2e-448f-4f58-acb8-4db1381f5de3', new DateTimeImmutable('1991-11-24 01:00:00')),
        ], iterator_to_array($this->iterator));

        self::assertEquals(2, $this->iterator->getNextPageToken()->getOffset());

        $request = $this->prophesize(Request::class);
        $request->query = new ParameterBag(['continue' => 'bfdc40_2_hzr9o9']);

        $this->iterator->setCurrentPage(PageToken::fromRequest($request->reveal()));

        $expectedQuery = [
            'query' => [
                'bool' => [
                    'filter' => [
                        [
                            'range' => [
                                'timestamp' => ['gte' => '1991-11-24T01:00:00+0000'],
                            ],
                        ],
                    ],
                ],
            ],
            '_source' => [
                'timestamp',
            ],
            'sort' => [
                ['timestamp' => 'asc'],
                ['id' => 'asc'],
            ],
            'size' => 5,
            'seq_no_primary_term' => true,
            'version' => true,
        ];

        $response = new Response([
            'took' => 1,
            'timed_out' => false,
            '_shards' => [
                'total' => 5,
                'successful' => 5,
                'failed' => 0,
            ],
            'hits' => [
                'total' => 1000,
                'max_score' => 0.0,
                'hits' => [
                    [
                        '_index' => 'test-object',
                        '_type' => 'test-object',
                        '_score' => 1.0,
                        '_id' => '191a54d8-990c-4ea7-9a23-0aed29d1fffe',
                        '_source' => [
                            'timestamp' => '1991-11-24 01:00:00',
                        ],
                    ],
                    [
                        '_index' => 'test-object',
                        '_type' => 'test-object',
                        '_score' => 1.0,
                        '_id' => '84810e2e-448f-4f58-acb8-4db1381f5de3',
                        '_source' => [
                            'timestamp' => '1991-11-24 01:00:00',
                        ],
                    ],
                    [
                        '_index' => 'test-object',
                        '_type' => 'test-object',
                        '_score' => 1.0,
                        '_id' => '9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed',
                        '_source' => [
                            'timestamp' => '1991-11-24 01:00:00',
                        ],
                    ],
                    [
                        '_index' => 'test-object',
                        '_type' => 'test-object',
                        '_score' => 1.0,
                        '_id' => 'af6394a4-7344-4fe8-9748-e6c67eba5ade',
                        '_source' => [
                            'timestamp' => '1991-11-24 01:00:00',
                        ],
                    ],
                    [
                        '_index' => 'test-object',
                        '_type' => 'test-object',
                        '_score' => 1.0,
                        '_id' => 'eadd7470-95f5-47e8-8e74-083d45c307f6',
                        '_source' => [
                            'timestamp' => '1991-11-24 02:00:00',
                        ],
                    ],
                ],
            ],
        ], 200);

        $this->client->request('test-object/_search', 'POST', $expectedQuery, [])
            ->willReturn($response);

        self::assertEquals([
            new TestObject('9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed', new DateTimeImmutable('1991-11-24 01:00:00')),
            new TestObject('af6394a4-7344-4fe8-9748-e6c67eba5ade', new DateTimeImmutable('1991-11-24 01:00:00')),
            new TestObject('eadd7470-95f5-47e8-8e74-083d45c307f6', new DateTimeImmutable('1991-11-24 02:00:00')),
        ], iterator_to_array($this->iterator));
    }

    public function testPagerShouldReturnFirstPageWithTimestampDifference(): void
    {
        $expectedQuery = [
            'query' => [
                'bool' => [
                    'filter' => [
                        [
                            'range' => [
                                'timestamp' => ['gte' => '1991-11-24T02:00:00+0000'],
                            ],
                        ],
                    ],
                ],
            ],
            '_source' => [
                'timestamp',
            ],
            'sort' => [
                ['timestamp' => 'asc'],
                ['id' => 'asc'],
            ],
            'size' => 4,
            'seq_no_primary_term' => true,
            'version' => true,
        ];

        $response = new Response([
            'took' => 1,
            'timed_out' => false,
            '_shards' => [
                'total' => 4,
                'successful' => 4,
                'failed' => 0,
            ],
            'hits' => [
                'total' => 1000,
                'max_score' => 0.0,
                'hits' => [
                    [
                        '_index' => 'test-object',
                        '_type' => 'test-object',
                        '_score' => 1.0,
                        '_id' => '9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed',
                        '_source' => [
                            'timestamp' => '1991-11-24 02:30:00',
                        ],
                    ],
                    [
                        '_index' => 'test-object',
                        '_type' => 'test-object',
                        '_score' => 1.0,
                        '_id' => 'af6394a4-7344-4fe8-9748-e6c67eba5ade',
                        '_source' => [
                            'timestamp' => '1991-11-24 03:00:00',
                        ],
                    ],
                    [
                        '_index' => 'test-object',
                        '_type' => 'test-object',
                        '_score' => 1.0,
                        '_id' => '84810e2e-448f-4f58-acb8-4db1381f5de3',
                        '_source' => [
                            'timestamp' => '1991-11-24 04:00:00',
                        ],
                    ],
                    [
                        '_index' => 'test-object',
                        '_type' => 'test-object',
                        '_score' => 1.0,
                        '_id' => 'eadd7470-95f5-47e8-8e74-083d45c307f6',
                        '_source' => [
                            'timestamp' => '1991-11-24 05:00:00',
                        ],
                    ],
                ],
            ],
        ], 200);

        $this->client->request('test-object/_search', 'POST', $expectedQuery, [])
            ->willReturn($response);

        $request = $this->prophesize(Request::class);
        $request->query = new ParameterBag(['continue' => 'bfdew0_1_1jvdwz4']); // This token represents a request with the 02:00:00 timestamp

        $this->iterator->setCurrentPage(PageToken::fromRequest($request->reveal()));

        self::assertEquals([
            new TestObject('9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed', new DateTimeImmutable('1991-11-24 02:30:00')),
            new TestObject('af6394a4-7344-4fe8-9748-e6c67eba5ade', new DateTimeImmutable('1991-11-24 03:00:00')),
            new TestObject('84810e2e-448f-4f58-acb8-4db1381f5de3', new DateTimeImmutable('1991-11-24 04:00:00')),
        ], iterator_to_array($this->iterator));

        self::assertEquals('bfdkg0_1_1xirtcr', (string) $this->iterator->getNextPageToken());
    }

    public function testPagerShouldReturnFirstPageWithChecksumDifference(): void
    {
        $expectedQuery = [
            'query' => [
                'bool' => [
                    'filter' => [
                        [
                            'range' => [
                                'timestamp' => ['gte' => '1991-11-24T02:00:00+0000'],
                            ],
                        ],
                    ],
                ],
            ],
            '_source' => [
                'timestamp',
            ],
            'sort' => [
                ['timestamp' => 'asc'],
                ['id' => 'asc'],
            ],
            'size' => 4,
            'seq_no_primary_term' => true,
            'version' => true,
        ];

        $response = new Response([
            'took' => 1,
            'timed_out' => false,
            '_shards' => [
                'total' => 4,
                'successful' => 4,
                'failed' => 0,
            ],
            'hits' => [
                'total' => 1000,
                'max_score' => 0.0,
                'hits' => [
                    [
                        '_index' => 'test-object',
                        '_type' => 'test-object',
                        '_score' => 1.0,
                        '_id' => 'af6394a4-7344-4fe8-9748-e6c67eba5ade',
                        '_source' => [
                            'timestamp' => '1991-11-24 02:00:00',
                        ],
                    ],
                    [
                        '_index' => 'test-object',
                        '_type' => 'test-object',
                        '_score' => 1.0,
                        '_id' => '9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed',
                        '_source' => [
                            'timestamp' => '1991-11-24 03:00:00',
                        ],
                    ],
                    [
                        '_index' => 'test-object',
                        '_type' => 'test-object',
                        '_score' => 1.0,
                        '_id' => '191a54d8-990c-4ea7-9a23-0aed29d1fffe',
                        '_source' => [
                            'timestamp' => '1991-11-24 04:00:00',
                        ],
                    ],
                    [
                        '_index' => 'test-object',
                        '_type' => 'test-object',
                        '_score' => 1.0,
                        '_id' => 'eadd7470-95f5-47e8-8e74-083d45c307f6',
                        '_source' => [
                            'timestamp' => '1991-11-24 05:00:00',
                        ],
                    ],
                ],
            ],
        ], 200);

        $this->client->request('test-object/_search', 'POST', $expectedQuery, [])
            ->willReturn($response);

        $request = $this->prophesize(Request::class);
        $request->query = new ParameterBag(['continue' => 'bfdew0_1_1jvdwz4']); // This token represents a request with the 02:00:00 timestamp

        $this->iterator->setCurrentPage(PageToken::fromRequest($request->reveal()));

        self::assertEquals([
            new TestObject('af6394a4-7344-4fe8-9748-e6c67eba5ade', new DateTimeImmutable('1991-11-24 02:00:00')),
            new TestObject('9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed', new DateTimeImmutable('1991-11-24 03:00:00')),
            new TestObject('191a54d8-990c-4ea7-9a23-0aed29d1fffe', new DateTimeImmutable('1991-11-24 04:00:00')),
        ], iterator_to_array($this->iterator));

        self::assertEquals('bfdkg0_1_7gqxdp', (string) $this->iterator->getNextPageToken());
    }

    public function testPageOffsetShouldWork(): void
    {
        $expectedQuery = [
            'query' => [
                'bool' => (object)[],
            ],
            '_source' => [
                'timestamp',
            ],
            'sort' => [
                ['timestamp' => 'asc'],
                ['id' => 'asc'],
            ],
            'size' => 3,
            'from' => 2,
            'seq_no_primary_term' => true,
            'version' => true,
        ];

        $response = new Response([
            'took' => 1,
            'timed_out' => false,
            '_shards' => [
                'total' => 4,
                'successful' => 4,
                'failed' => 0,
            ],
            'hits' => [
                'total' => 1000,
                'max_score' => 0.0,
                'hits' => [
                    [
                        '_index' => 'test-object',
                        '_type' => 'test-object',
                        '_score' => 1.0,
                        '_id' => 'af6394a4-7344-4fe8-9748-e6c67eba5ade',
                        '_source' => [
                            'timestamp' => '1991-11-24 02:00:00',
                        ],
                    ],
                    [
                        '_index' => 'test-object',
                        '_type' => 'test-object',
                        '_score' => 1.0,
                        '_id' => '9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed',
                        '_source' => [
                            'timestamp' => '1991-11-24 03:00:00',
                        ],
                    ],
                    [
                        '_index' => 'test-object',
                        '_type' => 'test-object',
                        '_score' => 1.0,
                        '_id' => '191a54d8-990c-4ea7-9a23-0aed29d1fffe',
                        '_source' => [
                            'timestamp' => '1991-11-24 04:00:00',
                        ],
                    ],
                ],
            ],
        ], 200);

        $this->client->request('test-object/_search', 'POST', $expectedQuery, [])
            ->willReturn($response);

        $this->iterator->setPageSize(3);
        $this->iterator->setCurrentPage(new PageOffset(2));

        self::assertEquals([
            new TestObject('af6394a4-7344-4fe8-9748-e6c67eba5ade', new DateTimeImmutable('1991-11-24 02:00:00')),
            new TestObject('9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed', new DateTimeImmutable('1991-11-24 03:00:00')),
            new TestObject('191a54d8-990c-4ea7-9a23-0aed29d1fffe', new DateTimeImmutable('1991-11-24 04:00:00')),
        ], iterator_to_array($this->iterator));
    }

    public function testPageNumberShouldWork(): void
    {
        $expectedQuery = [
            'query' => [
                'bool' => (object)[],
            ],
            '_source' => [
                'timestamp',
            ],
            'sort' => [
                ['timestamp' => 'asc'],
                ['id' => 'asc'],
            ],
            'size' => 3,
            'from' => 3,
            'seq_no_primary_term' => true,
            'version' => true,
        ];

        $response = new Response([
            'took' => 1,
            'timed_out' => false,
            '_shards' => [
                'total' => 4,
                'successful' => 4,
                'failed' => 0,
            ],
            'hits' => [
                'total' => 1000,
                'max_score' => 0.0,
                'hits' => [
                    [
                        '_index' => 'test-object',
                        '_type' => 'test-object',
                        '_score' => 1.0,
                        '_id' => '9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed',
                        '_source' => [
                            'timestamp' => '1991-11-24 03:00:00',
                        ],
                    ],
                    [
                        '_index' => 'test-object',
                        '_type' => 'test-object',
                        '_score' => 1.0,
                        '_id' => '84810e2e-448f-4f58-acb8-4db1381f5de3',
                        '_source' => [
                            'timestamp' => '1991-11-24 04:00:00',
                        ],
                    ],
                ],
            ],
        ], 200);

        $this->client->request('test-object/_search', 'POST', $expectedQuery, [])
            ->willReturn($response);

        $this->iterator->setPageSize(3);
        $this->iterator->setCurrentPage(new PageNumber(2));

        self::assertEquals([
            new TestObject('9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed', new DateTimeImmutable('1991-11-24 03:00:00')),
            new TestObject('84810e2e-448f-4f58-acb8-4db1381f5de3', new DateTimeImmutable('1991-11-24 04:00:00')),
        ], iterator_to_array($this->iterator));
    }
}
