<?php

declare(strict_types=1);

namespace Solido\Pagination;

use Solido\Common\AdapterFactory;
use Solido\Common\RequestAdapter\RequestAdapterInterface;
use Solido\Pagination\Exception\InvalidArgumentException;

use function is_numeric;

/**
 * Represents the page number.
 */
final class PageNumber
{
    public function __construct(private readonly int $number)
    {
        if ($number < 1) {
            throw new InvalidArgumentException('Page number cannot be less than 1');
        }
    }

    public function __toString(): string
    {
        return (string) $this->number;
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

        $page = $request->hasQueryParam('page') ? $request->getQueryParam('page') : null;
        if (empty($page) || ! is_numeric($page)) {
            return null;
        }

        return new self((int) $page);
    }

    public function getPageNumber(): int
    {
        return $this->number;
    }
}
