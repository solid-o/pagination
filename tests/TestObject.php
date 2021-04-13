<?php

declare(strict_types=1);

namespace Solido\Pagination\Tests;

class TestObject
{
    /** @var mixed */
    public $id;

    /** @var mixed */
    public $timestamp;

    /** @var RelatedTestObject */
    public $related;

    public function __construct($id, $timestamp, $related = null)
    {
        $this->id = $id;
        $this->timestamp = $timestamp;
        $this->related = $related;
    }
}
