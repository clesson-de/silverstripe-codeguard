<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Check;

enum CheckStatus: string
{
    case PASS = 'pass';
    case WARN = 'warning';
    case FAIL = 'fail';
    case ERROR = 'error';
    case SKIP = 'skipped';
}
