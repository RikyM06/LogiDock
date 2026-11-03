<?php

declare(strict_types=1);

namespace LogiDock\Controller;

use LogiDock\Core\Request;
use LogiDock\Core\Response;
use LogiDock\Core\View;

final class HomeController
{
    public function __construct(private readonly View $view)
    {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return Response::html($this->view->render('home', ['title' => 'Home']));
    }
}
