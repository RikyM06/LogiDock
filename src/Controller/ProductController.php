<?php

declare(strict_types=1);

namespace LogiDock\Controller;

use LogiDock\Core\HttpException;
use LogiDock\Core\Request;
use LogiDock\Core\Response;
use LogiDock\Core\Session;
use LogiDock\Core\ValidationException;
use LogiDock\Core\View;
use LogiDock\Enum\UserRole;
use LogiDock\Model\Product;
use LogiDock\Service\AuthService;
use LogiDock\Service\ProductService;

/**
 * Controller sottile: legge la richiesta, chiama il service, sceglie la risposta.
 * Nessuna regola di business qui. Le rotte di scrittura sono riservate agli admin
 * dal middleware (vedi config/routes.php).
 */
final class ProductController
{
    public function __construct(
        private readonly View $view,
        private readonly ProductService $products,
        private readonly AuthService $auth,
        private readonly Session $session
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 100);

        return Response::html($this->view->render('products/index', [
            'title' => 'Prodotti',
            'products' => $this->products->search($search),
            'search' => $search,
            'canManage' => $this->auth->hasRole(UserRole::Admin),
        ]));
    }

    /**
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        return $this->renderForm(
            'Nuovo prodotto',
            '/products',
            ['code' => '', 'description' => '', 'unit_of_measure' => '', 'unit_weight_kg' => '']
        );
    }

    /**
     * @param array<string, string> $params
     */
    public function store(Request $request, array $params): Response
    {
        try {
            $this->products->create($request->allInput());
        } catch (ValidationException $e) {
            return $this->renderForm('Nuovo prodotto', '/products', $this->formValues($request), $e->errors(), 422);
        }

        $this->session->flash('success', 'Prodotto creato.');

        return Response::redirect('/products');
    }

    /**
     * @param array<string, string> $params
     */
    public function edit(Request $request, array $params): Response
    {
        $product = $this->findOrFail($params);

        return $this->renderForm('Modifica prodotto', '/products/' . $product->id, [
            'code' => $product->code,
            'description' => $product->description,
            'unit_of_measure' => $product->unitOfMeasure,
            'unit_weight_kg' => $product->unitWeightKg,
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $product = $this->findOrFail($params);

        try {
            $this->products->update($product->id, $request->allInput());
        } catch (ValidationException $e) {
            return $this->renderForm(
                'Modifica prodotto',
                '/products/' . $product->id,
                $this->formValues($request),
                $e->errors(),
                422
            );
        }

        $this->session->flash('success', 'Prodotto aggiornato.');

        return Response::redirect('/products');
    }

    /**
     * @param array<string, string> $params
     */
    public function deactivate(Request $request, array $params): Response
    {
        $product = $this->findOrFail($params);
        $this->products->deactivate($product->id);
        $this->session->flash('success', 'Prodotto ' . $product->code . ' disattivato.');

        return Response::redirect('/products');
    }

    /**
     * @param array<string, string> $params
     */
    public function activate(Request $request, array $params): Response
    {
        $product = $this->findOrFail($params);
        $this->products->activate($product->id);
        $this->session->flash('success', 'Prodotto ' . $product->code . ' riattivato.');

        return Response::redirect('/products');
    }

    /**
     * @param array<string, string> $params
     */
    private function findOrFail(array $params): Product
    {
        $id = $params['id'] ?? '';

        // Un id non numerico o inesistente è semplicemente una pagina che non c'è.
        $product = ctype_digit($id) ? $this->products->find((int) $id) : null;
        if ($product === null) {
            throw new HttpException(404, 'Prodotto non trovato: ' . $id);
        }

        return $product;
    }

    /**
     * Solo i campi del form, sempre come stringhe: sono i valori da rimettere nei campi dopo un errore.
     *
     * @return array<string, string>
     */
    private function formValues(Request $request): array
    {
        $values = [];
        foreach (['code', 'description', 'unit_of_measure', 'unit_weight_kg'] as $field) {
            $values[$field] = (string) $request->input($field, '');
        }

        return $values;
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function renderForm(string $title, string $action, array $values, array $errors = [], int $status = 200): Response
    {
        return Response::html($this->view->render('products/form', [
            'title' => $title,
            'action' => $action,
            'values' => $values,
            'errors' => $errors,
            'units' => ProductService::UNITS,
        ]), $status);
    }
}
