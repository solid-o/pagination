<?php

declare(strict_types=1);

namespace Solido\Pagination\Tests;

class RelatedTestObject
{
    /** @var mixed */
    public $id;

    public function __construct($id)
    {
        $this->id = $id;
    }
}
