<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Exception;

use Throwable;

/**
 * Implemented by every exception the library throws, so a `catch` around a traversal can tell
 * a contract violation from an exception a visitor threw on purpose.
 */
interface ExceptionInterface extends Throwable
{
}
