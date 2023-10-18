<?php

declare(strict_types=1);

namespace Solido\Pagination\Accessor;

interface ValueAccessorInterface
{
    /**
     * Gets an object value at given path.
     */
    public function getValue(object $object, string $propertyPath): mixed;
}
