<?php

declare(strict_types=1);

namespace LogiDock\Service;

/**
 * Dice quante righe mostrare per pagina nelle liste: e' un'impostazione dell'admin (list_page_size),
 * non un numero scritto nel codice.
 */
final class ListPager
{
    public function __construct(private readonly SettingService $settings)
    {
    }

    public function perPage(): int
    {
        return $this->settings->getInt(SettingService::LIST_PAGE_SIZE);
    }
}
