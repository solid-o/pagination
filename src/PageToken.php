<?php

declare(strict_types=1);

namespace Solido\Pagination;

use Solido\Pagination\Exception\InvalidArgumentException;
use Solido\Pagination\Exception\InvalidTokenException;
use Symfony\Component\HttpFoundation\Request;

use function base64_encode;
use function base_convert;
use function count;
use function explode;
use function implode;
use function is_numeric;
use function Safe\base64_decode;
use function Safe\preg_match;
use function Safe\substr;
use function strpos;

/**
 * This class is the object representation of a ContinuousToken.
 * The three fields are each:.
 *
 * - the timestamp representation for an object (default: an unix timestamp), this will be calculated from a getPageableTimestamp function of a PageableInterface object
 * - the offset of the object relative to similar objects with the same timestamp (eg. 3 object with same timestamp, the second one will be represented by a "2" offset)
 * - the checksum which will work as following:
 *
 *   "given a request, i'll get the last timestamp which will be used as a reference. Then i'll work backwards collecting every entity with the same timestamp.
 *    I'll get the ids of each of these entities with the same timestamp, and put them into an array. The array will be imploded into a string which will be subjected
 *    to a crc32 function"
 *
 *   The checksum is there to control cases in which entities are modified into the database. The pager will control the checksum, and in case of a conflict (the database
 *   has changed), it will notify it to the client (mostly the fallback procedure is to return the first page of the entities).
 */
final class PageToken
{
    public const TOKEN_DELIMITER = '_';

    /**
     * The value used as starting point to "cut" the object set.
     *
     * @var mixed
     */
    private $orderValue;

    /**
     * How many elements should be skipped from the filtered set.
     */
    private int $offset;

    /**
     * The checksum if the first $offset elements.
     */
    private int $checksum;

    /**
     * @param mixed $orderValue
     */
    public function __construct($orderValue, int $offset, int $checksum)
    {
        if ($offset < 1) {
            throw new InvalidArgumentException('Offset cannot be less than 1');
        }

        $this->orderValue = $orderValue;
        $this->offset = $offset;
        $this->checksum = $checksum;
    }

    public function __toString(): string
    {
        /*
         * Example token, with "_" Delimiter: 1262338074_1_3632233996
         * - the first part is a standard unix timestamp ('U' format)
         * - the second part is an offset indicating the position among "same-timestamp"
         * entities(eg. if the last element of the page is second within 3 elements with the same timestamp, the value will be 2)
         * - the third part represents the checksum as crc32($entitiesWithSameTimestampInThisPage->getIds());
         */

        if (is_numeric($this->orderValue)) {
            $timestamp = base_convert((string) $this->orderValue, 10, 36);

            return implode(self::TOKEN_DELIMITER, [
                $timestamp,
                $this->offset,
                base_convert((string) $this->checksum, 10, 36),
            ]);
        }

        return implode(self::TOKEN_DELIMITER, [
            '=' . base64_encode((string) $this->orderValue),
            $this->offset,
            base_convert((string) $this->checksum, 10, 36),
        ]);
    }

    /**
     * Parses a token and returns a valid PageToken object.
     * Throws InvalidTokenException if $token is invalid.
     *
     * @throws InvalidTokenException
     */
    public static function parse(string $token): self
    {
        $tokenSplit = explode(self::TOKEN_DELIMITER, $token);
        if (count($tokenSplit) !== 3) {
            throw new InvalidTokenException('Malformed token');
        }

        [$orderValue, $offset, $checksum] = $tokenSplit;

        if (strpos($orderValue, '=') === 0) {
            $orderValue = base64_decode(substr($orderValue, 1));
        } else {
            $orderValue = (int) base_convert($tokenSplit[0], 36, 10);
        }

        return new self(
            $orderValue,
            (int) $offset,
            (int) base_convert($checksum, 36, 10)
        );
    }

    /**
     * Check whether the token is valid or not.
     */
    public static function isValid(string $token): bool
    {
        $tokenSplit = explode(self::TOKEN_DELIMITER, $token);
        if (count($tokenSplit) !== 3) {
            return false;
        }

        [$orderValue] = $tokenSplit;

        return strpos($orderValue, '=') === 0 || preg_match('/^[0-9a-z]*$/iu', $orderValue);
    }

    /**
     * Extract the token from the request and parses it.
     */
    public static function fromRequest(Request $request): ?self
    {
        $token = $request->query->get('continue');
        if (empty($token) || ! self::isValid($token)) {
            return null;
        }

        return self::parse($token);
    }

    /**
     * Gets the page order value (starting point).
     *
     * @return mixed
     */
    public function getOrderValue()
    {
        return $this->orderValue;
    }

    /**
     * Gets the filtered set offset.
     */
    public function getOffset(): int
    {
        return $this->offset;
    }

    /**
     * Gets the checksum of the first-$offset elements.
     */
    public function getChecksum(): int
    {
        return $this->checksum;
    }
}
