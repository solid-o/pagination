<?php

declare(strict_types=1);

namespace Solido\Pagination\Accessor;

use DateTimeInterface;

final class DateTimeValueAccessor implements ValueAccessorInterface
{
    public function __construct(private ValueAccessorInterface $decorated)
    {
    }

    public function getValue(object $object, string $propertyPath): mixed
    {
        $value = $this->decorated->getValue($object, $propertyPath);
        if ($value instanceof DateTimeInterface) {
            $value = $value->getTimestamp();
        }

        return $value;
    }
}
