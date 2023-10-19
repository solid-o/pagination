<?php

declare(strict_types=1);

namespace Solido\Pagination;

use Solido\Common\AdapterFactory;
use Solido\Common\RequestAdapter\RequestAdapterInterface;
use Solido\Pagination\Exception\InvalidArgumentException;

use function is_numeric;

/**
 * Represents the offset where the page starts.
 */
final class PageOffset
{
    public function __construct(private readonly int $offset)
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('Offset cannot be less than 0');
        }
    }

    public function __toString(): string
    {
        return (string) $this->offset;
    }

    /**
     * Extract the token from the request and parses it.
     */
    public static function fromRequest(object $request): self|null
    {
        if (! $request instanceof RequestAdapterInterface) {
            $adapterFactory = new AdapterFactory();
            $request = $adapterFactory->createRequestAdapter($request);
        }

        $offset = $request->hasQueryParam('offset') ? $request->getQueryParam('offset') : null;
        if (empty($offset) || ! is_numeric($offset)) {
            return null;
        }

        return new self((int) $offset);
    }

    public function getOffset(): int
    {
        return $this->offset;
    }
}
