<?php

declare(strict_types=1);

namespace Solido\Pagination\Accessor;

use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

final class ValueAccessor implements ValueAccessorInterface
{
    public function __construct(private PropertyAccessorInterface $propertyAccessor)
    {
    }

    public function getValue(object $object, string $propertyPath): mixed
    {
        return $this->propertyAccessor->getValue($object, $propertyPath);
    }
}
