<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Check;

enum Severity: string
{
    case INFO = 'info';
    case WARNING = 'warning';
    case ERROR = 'error';
    case HIGH = 'high';
    case CRITICAL = 'critical';
}
