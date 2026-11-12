<?php

declare(strict_types=1);

namespace LogiDock\Controller;

use LogiDock\Core\HttpException;
use LogiDock\Core\Request;
use LogiDock\Core\Response;
use LogiDock\Service\AuthService;
use LogiDock\Service\DocumentService;

/**
 * Mostra i PDF dei documenti già emessi. Li può aprire qualunque utente collegato, tranne il prospetto
 * del compenso del trasportatore (importi pagati): quello solo l'admin. Controller sottile: i documenti
 * si emettono alla partenza del viaggio (TripDepartureService), qui si leggono soltanto.
 */
final class DocumentController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly DocumentService $documents
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $user = $this->auth->user() ?? throw new HttpException(403, 'Utente non collegato');

        $id = $params['id'] ?? '';
        $document = ctype_digit($id) ? $this->documents->find((int) $id) : null;

        // Un non-admin davanti a un compenso riceve lo stesso "non trovato" di un documento inesistente:
        // non deve nemmeno poter capire che quel numero esiste.
        if ($document === null || ($document->isAdminOnly() && !$user->isAdmin())) {
            throw new HttpException(404, 'Documento non trovato: ' . $id);
        }

        $pdf = $this->documents->pdf($document->id)
            ?? throw new HttpException(404, 'Documento non trovato: ' . $id);

        return Response::pdf($pdf, $document->code() . '.pdf');
    }
}
