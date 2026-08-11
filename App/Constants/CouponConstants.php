<?php

namespace App\Constants;

final class CouponConstants
{ 
    // Code format: uppercase letters + digits + dashes, 6-50 chars
    public const CODE_MIN_LENGTH = 6;
        public const BULK_MAX_COUNT = 100;
 
    public const CODE_MAX_LENGTH = 50;
    public const CODE_PATTERN = '/^[A-Z0-9\-]+$/';

     public const MAX_USES_MIN = 1;
     public const MAX_USES_MAX = 100000;
 
    public const DEFAULT_SORT_BY = 'created_at';
    public const DEFAULT_SORT_DIR = 'DESC';

    public const ALLOWED_COUPON_SORT_FIELDS = ['id', 'code', 'max_uses', 'times_used', 'expire_at', 'created_at', 'is_active'];
    public const ALLOWED_MY_LECTURES_SORT_FIELDS = ['purchased_at', 'name', 'price'];

    public const DEFAULT_PAGE_SIZE = 20;
    public const MAX_PAGE_SIZE = 100;

    private function __construct()
    {
        // static-only class
    } 
}