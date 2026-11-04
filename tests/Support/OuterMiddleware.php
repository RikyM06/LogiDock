<?php

declare(strict_types=1);

namespace LogiDock\Tests\Support;

final class OuterMiddleware extends TraceMiddleware
{
    protected const LABEL = 'outer';
}
