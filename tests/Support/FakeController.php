<?php

declare(strict_types=1);

namespace LogiDock\Tests\Support;

use LogiDock\Core\Request;
use LogiDock\Core\Response;

/**
 * Controller finto per i test del router.
 */
final class FakeController
{
    /**
     * @param array<string, string> $params
     */
    public function hello(Request $request, array $params): Response
    {
        return Response::html('ciao');
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        return Response::html('id=' . ($params['id'] ?? '') . ';slug=' . ($params['slug'] ?? ''));
    }

    /**
     * @param array<string, string> $params
     */
    public function broken(Request $request, array $params): string
    {
        return 'non sono una Response';
    }
}
