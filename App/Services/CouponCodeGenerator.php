<?php

namespace App\Services;

use App\Contracts\CouponRepositoryInterface;
use App\Constants\CouponConstants;
use App\Exceptions\BusinessException;

/**
 * Generates short, readable, unique coupon codes server-side.
 *
 * Format: PREFIX + base36(current millisecond timestamp, last 6 chars)
 *         + 3 random uppercase alphanumeric chars
 * Example prefix "PHP" -> "PHPK3R7F2A"
 *
 * Uniqueness is verified against the DB (via codeExists) with bounded
 * retries — this MUST live on the backend: only the DB knows what codes
 * already exist, and bulk generation from N concurrent admins must not race.
 */
class CouponCodeGenerator
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no O/0/I/1 ambiguity
    private const MAX_ATTEMPTS_PER_CODE = 8;

    private CouponRepositoryInterface $couponRepo;

    public function __construct(CouponRepositoryInterface $couponRepo)
    {
        $this->couponRepo = $couponRepo;
    }

    public function generate(string $prefix): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix));
        if ($prefix === '') {
            $prefix = 'CPN';
        }

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS_PER_CODE; $attempt++) {
            $code = $this->buildCandidate($prefix);
            $code = $this->fitToLength($code);

            if (!$this->couponRepo->codeExists($code)) {
                return $code;
            }
        }

        throw new BusinessException(
            'Could not generate a unique coupon code, please try a different prefix',
            'CODE_GENERATION_EXHAUSTED'
        );
    }

    /**
     * Generates $count unique codes in one call — used by bulk creation so
     * we don't hit the DB once per coupon just to pick a code. Codes are
     * checked against each other in-memory AND against the DB.
     *
     * @return string[]
     */
    public function generateBatch(string $prefix, int $count): array
    {
        $codes = [];
        $seen = [];

        while (count($codes) < $count) {
            $code = $this->generateWithLocalSet($prefix, $seen);
            $codes[] = $code;
            $seen[$code] = true;
        }

        return $codes;
    }

    private function generateWithLocalSet(string $prefix, array $seen): string
    {
        $prefixClean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix)) ?: 'CPN';

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS_PER_CODE; $attempt++) {
            $code = $this->fitToLength($this->buildCandidate($prefixClean));
            if (!isset($seen[$code]) && !$this->couponRepo->codeExists($code)) {
                return $code;
            }
        }

        throw new BusinessException(
            'Could not generate enough unique coupon codes, please try a different prefix or smaller count',
            'CODE_GENERATION_EXHAUSTED'
        );
    }

    private function buildCandidate(string $prefix): string
    {
        $millis = (int) (microtime(true) * 1000);
        $timePart = strtoupper(substr(base_convert((string) $millis, 10, 36), -6));
        $randomPart = $this->randomChars(3);

        return $prefix . $timePart . $randomPart;
    }

    private function randomChars(int $length): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }
        return $out;
    } 

    private function fitToLength(string $code): string
    {
        $max = CouponConstants::CODE_MAX_LENGTH;
        $min = CouponConstants::CODE_MIN_LENGTH;

        if (strlen($code) > $max) {
            $code = substr($code, 0, $max);
        }
        if (strlen($code) < $min) {
            $code .= $this->randomChars($min - strlen($code));
        } 
        return $code;
    }
}