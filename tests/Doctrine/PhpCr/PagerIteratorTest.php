<?php

declare(strict_types=1);

namespace Solido\Pagination\Tests\Doctrine\PhpCr;

use DateTime;
use Doctrine\ODM\PHPCR\DocumentManagerInterface;
use Doctrine\ODM\PHPCR\Mapping\ClassMetadata;
use Doctrine\ODM\PHPCR\Mapping\ClassMetadataFactory;
use Doctrine\ODM\PHPCR\Query\Builder\ConverterPhpcr;
use Doctrine\ODM\PHPCR\Query\Builder\QueryBuilder;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use Jackalope\Factory;
use Jackalope\Query\QOM\NodeLocalName;
use Jackalope\Query\QOM\Ordering;
use Jackalope\Query\QOM\QueryObjectModel;
use Jackalope\Query\QOM\QueryObjectModelFactory;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Solido\Pagination\Doctrine\PhpCr\PagerIterator;
use Solido\Pagination\PageToken;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;

use function assert;
use function iterator_to_array;

class PagerIteratorTest extends TestCase
{
    use ProphecyTrait;

    /** @var DocumentManagerInterface|ObjectProphecy */
    private ObjectProphecy $dm;
    private QueryBuilder $queryBuilder;
    private PagerIterator $iterator;

    protected function setUp(): void
    {
        $this->dm = $this->prophesize(DocumentManagerInterface::class);
        $this->dm->hasLocaleChooserStrategy(Argument::cetera())->willReturn();
        $this->dm->getMetadataFactory()->willReturn($factory = $this->prophesize(ClassMetadataFactory::class));
        $this->dm->getClassMetadata(TestDocument::class)
            ->willReturn($metadata = new ClassMetadata(TestDocument::class));

        $factory->getMetadataFor(TestDocument::class)->willReturn($metadata);

        $metadata->wakeupReflection(new RuntimeReflectionService());
        $metadata->mapField([
            'fieldName' => 'id',
            'uuid' => true,
        ]);
        $metadata->mapField([
            'fieldName' => 'timestamp',
            'type' => 'date',
        ]);
        $metadata->mapField([
            'fieldName' => 'name',
            'type' => 'nodename',
        ]);

        $this->queryBuilder = new QueryBuilder();
        $this->queryBuilder->from('a')->document(TestDocument::class, 'a');
        $this->queryBuilder->setConverter(new ConverterPhpcr($this->dm->reveal(), new QueryObjectModelFactory(new Factory())));

        $this->iterator = new PagerIterator($this->queryBuilder, ['timestamp', 'id']);
        $this->iterator->setPageSize(3);
    }

    public function testPagerShouldGenerateFirstPageWithToken(): void
    {
        $request = $this->prophesize(Request::class);
        $request->query = new ParameterBag([]);

        $this->iterator->setToken(PageToken::fromRequest($request->reveal()));
        $this->dm->getDocumentsByPhpcrQuery(Argument::cetera())->willReturn([
            TestDocument::create('b4902bde-28d2-4ff9-8971-8bfeb3e943c1', new DateTime('1991-11-24 00:00:00')),
            TestDocument::create('191a54d8-990c-4ea7-9a23-0aed29d1fffe', new DateTime('1991-11-24 01:00:00')),
            TestDocument::create('9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed', new DateTime('1991-11-24 02:00:00')),
        ]);

        self::assertEquals([
            TestDocument::create('b4902bde-28d2-4ff9-8971-8bfeb3e943c1', new DateTime('1991-11-24 00:00:00')),
            TestDocument::create('191a54d8-990c-4ea7-9a23-0aed29d1fffe', new DateTime('1991-11-24 01:00:00')),
            TestDocument::create('9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed', new DateTime('1991-11-24 02:00:00')),
        ], iterator_to_array($this->iterator));

        self::assertEquals('bfdew0_1_1jvdwz4', (string) $this->iterator->getNextPageToken());
    }

    public function testPagerShouldGenerateSecondPageWithTokenAndLastPage(): void
    {
        $this->dm->getDocumentsByPhpcrQuery(Argument::cetera())->willReturn([
            TestDocument::create('9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed', new DateTime('1991-11-24 02:00:00')),
            TestDocument::create('af6394a4-7344-4fe8-9748-e6c67eba5ade', new DateTime('1991-11-24 03:00:00')),
            TestDocument::create('84810e2e-448f-4f58-acb8-4db1381f5de3', new DateTime('1991-11-24 04:00:00')),
            TestDocument::create('eadd7470-95f5-47e8-8e74-083d45c307f6', new DateTime('1991-11-24 05:00:00')),
        ]);

        $request = $this->prophesize(Request::class);
        $request->query = new ParameterBag(['continue' => 'bfdew0_1_1jvdwz4']);

        $this->iterator->setToken(PageToken::fromRequest($request->reveal()));

        self::assertEquals([
            TestDocument::create('af6394a4-7344-4fe8-9748-e6c67eba5ade', new DateTime('1991-11-24 03:00:00')),
            TestDocument::create('84810e2e-448f-4f58-acb8-4db1381f5de3', new DateTime('1991-11-24 04:00:00')),
            TestDocument::create('eadd7470-95f5-47e8-8e74-083d45c307f6', new DateTime('1991-11-24 05:00:00')),
        ], iterator_to_array($this->iterator));

        self::assertEquals('bfdn80_1_cukvcs', (string) $this->iterator->getNextPageToken());
    }

    public function testOffsetShouldWork(): void
    {
        $this->dm->getDocumentsByPhpcrQuery(Argument::cetera())->willReturn([
            TestDocument::create('b4902bde-28d2-4ff9-8971-8bfeb3e943c1', new DateTime('1991-11-24 00:00:00')),
            TestDocument::create('191a54d8-990c-4ea7-9a23-0aed29d1fffe', new DateTime('1991-11-24 01:00:00')),
            TestDocument::create('84810e2e-448f-4f58-acb8-4db1381f5de3', new DateTime('1991-11-24 01:00:00')),
        ]);

        $request = $this->prophesize(Request::class);
        $request->query = new ParameterBag([]);

        $this->iterator->setToken(PageToken::fromRequest($request->reveal()));

        self::assertEquals([
            TestDocument::create('b4902bde-28d2-4ff9-8971-8bfeb3e943c1', new DateTime('1991-11-24 00:00:00')),
            TestDocument::create('191a54d8-990c-4ea7-9a23-0aed29d1fffe', new DateTime('1991-11-24 01:00:00')),
            TestDocument::create('84810e2e-448f-4f58-acb8-4db1381f5de3', new DateTime('1991-11-24 01:00:00')),
        ], iterator_to_array($this->iterator));

        self::assertEquals(2, $this->iterator->getNextPageToken()->getOffset());

        $request = $this->prophesize(Request::class);
        $request->query = new ParameterBag(['continue' => 'bfdc40_2_hzr9o9']);

        $this->iterator->setToken(PageToken::fromRequest($request->reveal()));

        $this->dm->getDocumentsByPhpcrQuery(Argument::cetera())->willReturn([
            (object) TestDocument::create('191a54d8-990c-4ea7-9a23-0aed29d1fffe', new DateTime('1991-11-24 01:00:00')),
            (object) TestDocument::create('84810e2e-448f-4f58-acb8-4db1381f5de3', new DateTime('1991-11-24 01:00:00')),
            (object) TestDocument::create('9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed', new DateTime('1991-11-24 01:00:00')),
            (object) TestDocument::create('af6394a4-7344-4fe8-9748-e6c67eba5ade', new DateTime('1991-11-24 01:00:00')),
            (object) TestDocument::create('eadd7470-95f5-47e8-8e74-083d45c307f6', new DateTime('1991-11-24 02:00:00')),
        ]);

        self::assertEquals([
            TestDocument::create('9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed', new DateTime('1991-11-24 01:00:00')),
            TestDocument::create('af6394a4-7344-4fe8-9748-e6c67eba5ade', new DateTime('1991-11-24 01:00:00')),
            TestDocument::create('eadd7470-95f5-47e8-8e74-083d45c307f6', new DateTime('1991-11-24 02:00:00')),
        ], iterator_to_array($this->iterator));
    }

    public function testPagerShouldReturnFirstPageWithTimestampDifference(): void
    {
        $this->dm->getDocumentsByPhpcrQuery(Argument::cetera())->willReturn([
            (object) TestDocument::create('9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed', new DateTime('1991-11-24 02:30:00')),
            (object) TestDocument::create('af6394a4-7344-4fe8-9748-e6c67eba5ade', new DateTime('1991-11-24 03:00:00')),
            (object) TestDocument::create('84810e2e-448f-4f58-acb8-4db1381f5de3', new DateTime('1991-11-24 04:00:00')),
            (object) TestDocument::create('eadd7470-95f5-47e8-8e74-083d45c307f6', new DateTime('1991-11-24 05:00:00')),
        ]);

        $request = $this->prophesize(Request::class);
        $request->query = new ParameterBag(['continue' => 'bfdew0_1_1jvdwz4']); // This token represents a request with the 02:00:00 timestamp

        $this->iterator->setToken(PageToken::fromRequest($request->reveal()));

        self::assertEquals([
            TestDocument::create('9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed', new DateTime('1991-11-24 02:30:00')),
            TestDocument::create('af6394a4-7344-4fe8-9748-e6c67eba5ade', new DateTime('1991-11-24 03:00:00')),
            TestDocument::create('84810e2e-448f-4f58-acb8-4db1381f5de3', new DateTime('1991-11-24 04:00:00')),
        ], iterator_to_array($this->iterator));

        self::assertEquals('bfdkg0_1_1xirtcr', (string) $this->iterator->getNextPageToken());
    }

    public function testPagerShouldReturnFirstPageWithChecksumDifference(): void
    {
        $this->dm->getDocumentsByPhpcrQuery(Argument::cetera())->willReturn([
            (object) TestDocument::create('af6394a4-7344-4fe8-9748-e6c67eba5ade', new DateTime('1991-11-24 02:00:00')),
            (object) TestDocument::create('9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed', new DateTime('1991-11-24 03:00:00')),
            (object) TestDocument::create('191a54d8-990c-4ea7-9a23-0aed29d1fffe', new DateTime('1991-11-24 04:00:00')),
            (object) TestDocument::create('eadd7470-95f5-47e8-8e74-083d45c307f6', new DateTime('1991-11-24 05:00:00')),
        ]);

        $request = $this->prophesize(Request::class);
        $request->query = new ParameterBag(['continue' => 'bfdew0_1_183tket']); // This token represents a request with the 02:00:00 timestamp

        $this->iterator->setToken(PageToken::fromRequest($request->reveal()));

        self::assertEquals([
            TestDocument::create('af6394a4-7344-4fe8-9748-e6c67eba5ade', new DateTime('1991-11-24 02:00:00')),
            TestDocument::create('9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed', new DateTime('1991-11-24 03:00:00')),
            TestDocument::create('191a54d8-990c-4ea7-9a23-0aed29d1fffe', new DateTime('1991-11-24 04:00:00')),
        ], iterator_to_array($this->iterator));

        self::assertEquals('bfdkg0_1_7gqxdp', (string) $this->iterator->getNextPageToken());
    }

    public function testPagerShouldOrderByLocalName(): void
    {
        $this->iterator = new PagerIterator($this->queryBuilder, ['name', 'timestamp']);
        $this->iterator->setPageSize(3);

        $this->dm->getDocumentsByPhpcrQuery(Argument::that(static function (QueryObjectModel $arg) {
            $orderings = $arg->getOrderings();

            Assert::assertCount(2, $orderings);
            [$first] = $orderings;

            assert($first instanceof Ordering);
            Assert::assertInstanceOf(NodeLocalName::class, $first->getOperand());

            return true;
        }), Argument::cetera())
             ->willReturn([
                 TestDocument::create('b4902bde-28d2-4ff9-8971-8bfeb3e943c1', new DateTime('1991-11-24 00:00:00'), 'foo'),
                 TestDocument::create('191a54d8-990c-4ea7-9a23-0aed29d1fffe', new DateTime('1991-11-24 01:00:00'), 'bar'),
                 TestDocument::create('9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed', new DateTime('1991-11-24 02:00:00'), 'foobar'),
             ])
            ->shouldBeCalled();

        iterator_to_array($this->iterator);
        self::assertEquals('=Zm9vYmFy_1_68lkk0', (string) $this->iterator->getNextPageToken());

        $request = $this->prophesize(Request::class);
        $request->query = new ParameterBag(['continue' => '=Zm9vYmFy_1_68lkk0']); // This token represents a request with the 02:00:00 timestamp

        $this->iterator->setToken(PageToken::fromRequest($request->reveal()));
        $this->dm->getDocumentsByPhpcrQuery(Argument::that(static function (QueryObjectModel $arg) {
            $orderings = $arg->getOrderings();

            Assert::assertCount(2, $orderings);
            [$first] = $orderings;

            assert($first instanceof Ordering);
            Assert::assertInstanceOf(NodeLocalName::class, $first->getOperand());

            return true;
        }), Argument::cetera())
            ->willReturn([
                TestDocument::create('af6394a4-7344-4fe8-9748-e6c67eba5ade', new DateTime('1991-11-24 02:00:00')),
                TestDocument::create('9c5f6ff7-b28f-48fb-ba47-8bcc3b235bed', new DateTime('1991-11-24 03:00:00')),
                TestDocument::create('191a54d8-990c-4ea7-9a23-0aed29d1fffe', new DateTime('1991-11-24 04:00:00')),
            ])
            ->shouldBeCalled();

        iterator_to_array($this->iterator);
    }
}

class TestDocument
{
    public $id;
    public $name;
    public $timestamp;

    public static function create($id, $timestamp, $name = null)
    {
        $obj = new self();
        $obj->id = $id;
        $obj->name = $name;
        $obj->timestamp = $timestamp;

        return $obj;
    }
}
