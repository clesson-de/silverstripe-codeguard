<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Check;

enum CheckGroup: string
{
    case SECURITY = 'security';
    case COMPOSER = 'composer';
    case DOCUMENTATION = 'documentation';
    case FILES = 'files';
    case CODE_QUALITY = 'code-quality';
    case SILVERSTRIPE = 'silverstripe';
    case RELEASE = 'release';
}
