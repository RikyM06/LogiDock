<?php

declare(strict_types=1);

namespace LogiDock\Tests\Support;

final class InnerMiddleware extends TraceMiddleware
{
    protected const LABEL = 'inner';
}
