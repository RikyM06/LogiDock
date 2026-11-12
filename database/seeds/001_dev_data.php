<?php

declare(strict_types=1);

use LogiDock\Core\Database;
use LogiDock\Pdf\DocumentRenderer;
use LogiDock\Repository\CustomerRepository;
use LogiDock\Repository\NumberSequenceRepository;
use LogiDock\Repository\OrderRepository;
use LogiDock\Repository\PalletMovementRepository;
use LogiDock\Repository\PalletRepository;
use LogiDock\Repository\PalletTypeRepository;
use LogiDock\Repository\PlanningRepository;
use LogiDock\Repository\ProductRepository;
use LogiDock\Repository\RoundRepository;
use LogiDock\Repository\SettingRepository;
use LogiDock\Repository\SupplierDocumentRepository;
use LogiDock\Repository\SupplierRepository;
use LogiDock\Repository\TransportDocumentRepository;
use LogiDock\Repository\TripRepository;
use LogiDock\Repository\WarehouseLocationRepository;
use LogiDock\Service\CustomerService;
use LogiDock\Service\DocumentService;
use LogiDock\Service\InboundService;
use LogiDock\Service\OrderService;
use LogiDock\Service\PasswordPolicy;
use LogiDock\Service\PalletTypeService;
use LogiDock\Service\ProductService;
use LogiDock\Service\RoundService;
use LogiDock\Service\SettingService;
use LogiDock\Service\SupplierService;
use LogiDock\Service\TripDepartureService;
use LogiDock\Service\TripService;
use LogiDock\Service\WarehouseLocationService;

// Dati di esempio per lo sviluppo. Ripetibile: cerca ogni record per chiave naturale
// (email, codice...) e lo inserisce solo se manca, quindi non crea doppioni né sovrascrive nulla.
// Le password degli utenti di esempio arrivano da .env, mai dal codice.

return static function (Database $database): void {
    $pdo = $database->pdo();

    $adminPassword = env('SEED_ADMIN_PASSWORD');
    $operatorPassword = env('SEED_OPERATOR_PASSWORD');
    if ($adminPassword === '' || $operatorPassword === '') {
        throw new RuntimeException('Imposta SEED_ADMIN_PASSWORD e SEED_OPERATOR_PASSWORD in .env prima di lanciare il seed.');
    }
    // Stesse regole della gestione utenti: anche gli utenti di esempio non nascono con password deboli.
    // Il controllo è prima di qualsiasi scrittura, quindi una password sbagliata non lascia il DB a metà.
    foreach (['SEED_ADMIN_PASSWORD' => $adminPassword, 'SEED_OPERATOR_PASSWORD' => $operatorPassword] as $key => $password) {
        $problem = PasswordPolicy::problem($password);
        if ($problem !== null) {
            throw new RuntimeException($key . ' in .env non va bene: ' . $problem);
        }
    }

    // Restituisce l'id della prima riga trovata, oppure null.
    $firstId = static function (string $sql, array $params) use ($pdo): ?int {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    };

    $insert = static function (string $sql, array $params) use ($pdo): int {
        $pdo->prepare($sql)->execute($params);

        return (int) $pdo->lastInsertId();
    };

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $daysAgo = static fn (int $days): string => $now->modify("-{$days} days")->format('Y-m-d H:i:s');
    $inDays = static fn (int $days): string => $now->modify("+{$days} days")->format('Y-m-d');
    $year = (int) $now->format('Y');

    // --- Utenti ---------------------------------------------------------------------------------
    $userIds = [];
    $users = [
        ['admin@logidock.test', 'Amministratore', $adminPassword, 'admin'],
        ['operatore@logidock.test', 'Operatore Demo', $operatorPassword, 'operatore'],
    ];
    foreach ($users as [$email, $name, $password, $role]) {
        $userIds[$role] = $firstId('SELECT id FROM users WHERE email = ?', [$email])
            ?? $insert(
                'INSERT INTO users (email, name, password_hash, role) VALUES (?, ?, ?, ?)',
                [$email, $name, password_hash($password, PASSWORD_DEFAULT), $role]
            );
    }

    // Un utente disattivato, per vedere lo stato nella gestione utenti. La password è casuale e non
    // viene salvata da nessuna parte: nessuno la conosce, e comunque un utente disattivato non entra.
    if ($firstId('SELECT id FROM users WHERE email = ?', ['magazziniere@logidock.test']) === null) {
        $insert(
            'INSERT INTO users (email, name, password_hash, role, is_active) VALUES (?, ?, ?, ?, 0)',
            ['magazziniere@logidock.test', 'Magazziniere Demo', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'operatore']
        );
    }

    // --- Prodotti (codice, descrizione, unità, peso unitario in kg) -----------------------------
    $productIds = [];
    $products = [
        ['ACQ-050', 'Acqua naturale 0,5 L - cartone da 24', 'ct', '12.600'],
        ['PAS-500', 'Pasta di semola 500 g - cartone da 12', 'ct', '6.300'],
        ['OLI-1L', 'Olio extravergine di oliva 1 L - cartone da 6', 'ct', '5.900'],
        ['SCA-PIA', 'Scatola di cartone 40x30x20 cm', 'pz', '0.350'],
        ['PAN-ECO', 'Pannolini taglia 4 - confezione', 'cf', '3.200'],
        ['DET-LIQ', 'Detersivo liquido 2 L - cartone da 4', 'ct', '8.800'],
    ];
    foreach ($products as [$code, $description, $unit, $weight]) {
        $productIds[$code] = $firstId('SELECT id FROM products WHERE code = ?', [$code])
            ?? $insert(
                'INSERT INTO products (code, description, unit_of_measure, unit_weight_kg) VALUES (?, ?, ?, ?)',
                [$code, $description, $unit, $weight]
            );
    }

    // --- Fornitori (dati di fantasia: dominio .test riservato, partite IVA e telefoni fittizi) ----
    // Nella scheda fornitore tutti i campi sono obbligatori, quindi anche i dati di esempio li hanno.
    $supplierIds = [];
    $suppliers = [
        ['Sorgenti Alpine S.r.l.', 'IT00000000101', 'Via delle Fonti 12, Bergamo', 'ordini@sorgentialpine.test', '035 0000101'],
        ['Dispensa Italia S.r.l.', 'IT00000000102', 'Via Roma 45, Parma', 'ordini@dispensaitalia.test', '0521 0000102'],
        ['Cartotecnica Lombarda S.r.l.', 'IT00000000103', 'Via dell\'Industria 8, Brescia', 'vendite@cartotecnicalombarda.test', '030 0000103'],
        ['Igiene Casa Distribuzione S.r.l.', 'IT00000000104', 'Via Mazzini 101, Verona', 'info@igienecasa.test', '045 0000104'],
    ];
    foreach ($suppliers as [$name, $taxCode, $address, $email, $phone]) {
        $existing = $firstId('SELECT id FROM suppliers WHERE name = ?', [$name]);

        if ($existing === null) {
            $supplierIds[$name] = $insert(
                'INSERT INTO suppliers (name, tax_code, address, contact_email, phone) VALUES (?, ?, ?, ?, ?)',
                [$name, $taxCode, $address, $email, $phone]
            );
            continue;
        }

        // Fornitore già presente (seed di prima): si completano solo i campi vuoti, senza sovrascrivere nulla.
        $pdo->prepare(
            'UPDATE suppliers SET tax_code = COALESCE(tax_code, ?), address = COALESCE(address, ?),
                                  contact_email = COALESCE(contact_email, ?), phone = COALESCE(phone, ?)
             WHERE id = ?'
        )->execute([$taxCode, $address, $email, $phone, $existing]);
        $supplierIds[$name] = $existing;
    }

    // --- Posizioni di magazzino -----------------------------------------------------------------
    $locationIds = [];
    $locations = [
        ['IN-01', 'Ingresso', 'Area di scarico e smistamento'],
        ['A-01-01', 'A', null],
        ['A-01-02', 'A', null],
        ['A-01-03', 'A', null],
        ['A-02-01', 'A', null],
        ['B-01-01', 'B', null],
        ['B-01-02', 'B', null],
    ];
    foreach ($locations as [$code, $zone, $description]) {
        $locationIds[$code] = $firstId('SELECT id FROM warehouse_locations WHERE code = ?', [$code])
            ?? $insert(
                'INSERT INTO warehouse_locations (code, zone, description) VALUES (?, ?, ?)',
                [$code, $zone, $description]
            );
    }

    // --- Giri, clienti, trasportatore e tipi di rimorchio (nomi di fantasia) --------------------
    $roundIds = [];
    foreach ([['Giro Nord', 'Bergamo, Brescia, Lecco'], ['Giro Sud', 'Parma, Piacenza, Cremona']] as [$name, $description]) {
        $roundIds[$name] = $firstId('SELECT id FROM rounds WHERE name = ?', [$name])
            ?? $insert('INSERT INTO rounds (name, description) VALUES (?, ?)', [$name, $description]);
    }

    $customers = [
        ['Supermercato Il Girasole', 'Via Milano 21, Bergamo', 'Giro Nord'],
        ['Alimentari Bianchi', 'Via Garibaldi 5, Lecco', 'Giro Nord'],
        ['Emporio del Borgo', 'Piazza Duomo 3, Parma', 'Giro Sud'],
    ];
    foreach ($customers as [$name, $address, $round]) {
        if ($firstId('SELECT id FROM customers WHERE name = ?', [$name]) === null) {
            $insert(
                'INSERT INTO customers (name, address, round_id) VALUES (?, ?, ?)',
                [$name, $address, $roundIds[$round]]
            );
        }
    }

    $carrierId = $firstId('SELECT id FROM carriers WHERE name = ?', ['Autotrasporti Verdi'])
        ?? $insert(
            'INSERT INTO carriers (name, phone, trip_fee) VALUES (?, ?, ?)',
            ['Autotrasporti Verdi', '0350000000', '180.00']
        );

    // [nome, lunghezza, larghezza e altezza interna in mm, portata in kg]
    $trailerTypes = [
        ['Rimorchio 7,2 m', 7200, 2450, 2600, '6500.00'],
        ['Semirimorchio 13,6 m', 13600, 2450, 2700, '24000.00'],
    ];
    foreach ($trailerTypes as $trailerType) {
        if ($firstId('SELECT id FROM trailer_types WHERE name = ?', [$trailerType[0]]) === null) {
            $insert(
                'INSERT INTO trailer_types (name, length_mm, width_mm, inner_height_mm, max_weight_kg) VALUES (?, ?, ?, ?, ?)',
                $trailerType
            );
        }
    }

    // --- Mezzi -----------------------------------------------------------------------------------
    // Il programma dei viaggi usa solo i mezzi con un tipo di rimorchio (gli servono le misure del
    // pianale). Il camion è di un trasportatore, il bilico è dell'azienda (nessun trasportatore).
    // [targa, descrizione, portata in kg, bancali al massimo, tipo di rimorchio, trasportatore]
    $vehicles = [
        ['FG456HJ', 'Camion 12 bancali', '6500.00', 12, 'Rimorchio 7,2 m', $carrierId],
        ['KL789MN', 'Bilico 33 bancali', '24000.00', 33, 'Semirimorchio 13,6 m', null],
    ];
    foreach ($vehicles as [$plate, $description, $maxWeight, $maxPallets, $trailerType, $vehicleCarrierId]) {
        $vehicleTrailerTypeId = $firstId('SELECT id FROM trailer_types WHERE name = ?', [$trailerType]);
        $existing = $firstId('SELECT id FROM vehicles WHERE plate = ?', [$plate]);

        if ($existing === null) {
            $insert(
                'INSERT INTO vehicles (plate, description, max_weight_kg, max_pallets, trailer_type_id, carrier_id) VALUES (?, ?, ?, ?, ?, ?)',
                [$plate, $description, $maxWeight, $maxPallets, $vehicleTrailerTypeId, $vehicleCarrierId]
            );
            continue;
        }

        // Mezzo già presente (seed di prima): si completano solo i campi vuoti, senza sovrascrivere nulla.
        $pdo->prepare(
            'UPDATE vehicles SET trailer_type_id = COALESCE(trailer_type_id, ?), carrier_id = COALESCE(carrier_id, ?)
             WHERE id = ?'
        )->execute([$vehicleTrailerTypeId, $vehicleCarrierId, $existing]);
    }

    // --- Bancali e movimenti --------------------------------------------------------------------
    // [prodotto, fornitore, posizione attuale, quantità, lotto, scadenza (giorni da oggi), arrivato (giorni fa),
    //  arrivo in IN-01 poi spostato?, tipo di bancale]
    $pallets = [
        ['ACQ-050', 'Sorgenti Alpine S.r.l.', 'A-01-01', '120.000', 'L2610A', 300, 20, false, 'EPAL1'],
        ['ACQ-050', 'Sorgenti Alpine S.r.l.', 'A-01-02', '120.000', 'L2610A', 300, 20, true, 'EPAL1'],
        ['PAS-500', 'Dispensa Italia S.r.l.', 'A-02-01', '96.000', 'P2609C', 180, 14, false, 'EPAL1'],
        ['OLI-1L', 'Dispensa Italia S.r.l.', 'B-01-01', '80.000', 'O2608B', 400, 9, false, 'EPAL3'],
        ['SCA-PIA', 'Cartotecnica Lombarda S.r.l.', 'B-01-02', '500.000', null, null, 5, false, 'EPAL3'],
        ['DET-LIQ', 'Igiene Casa Distribuzione S.r.l.', 'A-01-03', '60.000', 'D2610F', 700, 3, false, 'EPAL1'],
        ['PAN-ECO', 'Igiene Casa Distribuzione S.r.l.', 'IN-01', '150.000', 'N2611A', null, 1, false, 'EPAL3'],
    ];

    $operatorId = $userIds['operatore'];
    foreach ($pallets as $index => [$productCode, $supplier, $location, $quantity, $lot, $expiry, $received, $movedAfter, $palletType]) {
        $code = sprintf('PAL-%d-%06d', $year, $index + 1);
        $demoPalletTypeId = $firstId('SELECT id FROM pallet_types WHERE code = ?', [$palletType]);

        if ($firstId('SELECT id FROM pallets WHERE code = ?', [$code]) !== null) {
            // Bancale già presente (seed di prima, senza tipo): si completa solo il tipo se manca.
            // Il controllo sul prodotto evita di toccare un bancale vero che avesse lo stesso codice.
            $pdo->prepare('UPDATE pallets SET pallet_type_id = COALESCE(pallet_type_id, ?) WHERE code = ? AND product_id = ?')
                ->execute([$demoPalletTypeId, $code, $productIds[$productCode]]);
            continue;
        }

        $arrivalLocation = $movedAfter ? 'IN-01' : $location;

        $palletId = $insert(
            'INSERT INTO pallets (code, product_id, supplier_id, location_id, pallet_type_id, quantity, initial_quantity,
                                  lot_number, expiry_date, received_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $code,
                $productIds[$productCode],
                $supplierIds[$supplier],
                $locationIds[$location],
                $demoPalletTypeId,
                $quantity,
                $quantity,
                $lot,
                $expiry === null ? null : $inDays($expiry),
                $daysAgo($received),
                $operatorId,
            ]
        );

        $movement = 'INSERT INTO pallet_movements (pallet_id, type, quantity_delta, from_location_id, to_location_id, user_id, notes, occurred_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)';

        $insert($movement, [
            $palletId, 'inbound', $quantity, null, $locationIds[$arrivalLocation], $operatorId,
            'Ingresso da ' . $supplier, $daysAgo($received),
        ]);

        if ($movedAfter) {
            $insert($movement, [
                $palletId, 'move', '0.000', $locationIds['IN-01'], $locationIds[$location], $operatorId,
                'Posizionamento a scaffale', $daysAgo($received - 1),
            ]);
        }
    }

    // Il contatore dei codici bancale riparte dall'ultimo usato.
    $pdo->prepare(
        'INSERT INTO number_sequences (type, year, last_number) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE last_number = GREATEST(last_number, VALUES(last_number))'
    )->execute(['pallet', $year, count($pallets)]);

    // --- Un arrivo completo (documento del fornitore + bancali) ---------------------------------
    // Registrato con lo stesso InboundService dell'applicazione: stessi controlli, stessi codici
    // bancale, stessi movimenti. Un bancale ha il peso segnato fuori tolleranza, per vedere l'avviso.
    $documentNumber = sprintf('DDT %d/0158', $year);
    $alreadyThere = $firstId(
        'SELECT id FROM supplier_documents WHERE supplier_id = ? AND number = ?',
        [$supplierIds['Dispensa Italia S.r.l.'], $documentNumber]
    );

    if ($alreadyThere === null) {
        $palletTypeId = static fn (string $code): string => (string) $firstId('SELECT id FROM pallet_types WHERE code = ?', [$code]);
        $settings = new SettingService(new SettingRepository($database), $database);

        $inbound = new InboundService(
            $database,
            new SupplierDocumentRepository($database),
            new PalletRepository($database),
            new PalletMovementRepository($database),
            new NumberSequenceRepository($database),
            new SupplierService(new SupplierRepository($database)),
            new ProductService(new ProductRepository($database)),
            new PalletTypeService(new PalletTypeRepository($database)),
            new WarehouseLocationService(new WarehouseLocationRepository($database)),
            $settings
        );

        // [prodotto, tipo bancale, quantità, prezzo unitario, peso segnato, lotto, scadenza (giorni), posizione]
        $lines = [
            ['PAS-500', 'EPAL1', '96', '7.80', '610', 'P2611D', 180, 'A-02-01'],
            ['PAS-500', 'EPAL1', '96', '7.80', '606', 'P2611D', 180, 'A-02-01'],
            ['OLI-1L', 'EPAL3', '80', '24.50', '520', 'O2611A', 400, 'B-01-01'], // 80 x 5,9 = 472 kg: +10%
        ];

        $inbound->receive([
            'supplier_id' => (string) $supplierIds['Dispensa Italia S.r.l.'],
            'number' => $documentNumber,
            'document_date' => substr($daysAgo(2), 0, 10),
            'received_date' => substr($daysAgo(1), 0, 10),
            'notes' => 'Consegna del mattino, scaricata alla baia 2.',
            'lines' => array_map(static fn (array $line): array => [
                'product_id' => (string) $productIds[$line[0]],
                'pallet_type_id' => $palletTypeId($line[1]),
                'quantity' => $line[2],
                'unit_price' => $line[3],
                'recorded_weight_kg' => $line[4],
                'lot_number' => $line[5],
                'expiry_date' => $inDays($line[6]),
                'location_id' => (string) $locationIds[$line[7]],
            ], $lines),
        ], $operatorId);
    }

    // --- Richieste dei clienti (solo se non ce n'è ancora nessuna) ------------------------------
    // Registrate con lo stesso OrderService dell'applicazione. L'Emporio chiede più acqua di quella
    // in magazzino, per vedere l'avviso di giacenza; una richiesta è annullata, per vedere gli stati.
    if ($firstId('SELECT id FROM orders LIMIT 1', []) === null) {
        $orderService = new OrderService(
            $database,
            new OrderRepository($database),
            new NumberSequenceRepository($database),
            new CustomerService(new CustomerRepository($database), new RoundService(new RoundRepository($database))),
            new ProductService(new ProductRepository($database))
        );
        $customerId = static fn (string $name): string => (string) $firstId('SELECT id FROM customers WHERE name = ?', [$name]);
        $today = substr($daysAgo(0), 0, 10);

        // [cliente, consegna tra (giorni), note, [[prodotto, quantità, prezzo di vendita], ...], annullata?]
        $demoOrders = [
            ['Supermercato Il Girasole', 2, 'Scarico dal retro, prima delle 9.', [['ACQ-050', '40', '4.20'], ['PAS-500', '30', '11.50'], ['OLI-1L', '12', '38.00']], false],
            ['Alimentari Bianchi', 2, null, [['DET-LIQ', '15', '14.90'], ['PAN-ECO', '60', '7.40']], false],
            ['Emporio del Borgo', 3, 'Consegna con sponda idraulica.', [['OLI-1L', '100', '37.50'], ['ACQ-050', '300', '4.10']], false],
            ['Supermercato Il Girasole', 5, 'Doppione inserito per errore.', [['SCA-PIA', '200', '0.90']], true],
        ];

        foreach ($demoOrders as [$customer, $deliveryIn, $notes, $orderLines, $cancelled]) {
            $orderId = $orderService->create([
                'customer_id' => $customerId($customer),
                'requested_date' => $today,
                'delivery_date' => $inDays($deliveryIn),
                'notes' => $notes ?? '',
                'lines' => array_map(static fn (array $line): array => [
                    'product_id' => (string) $productIds[$line[0]],
                    'quantity' => $line[1],
                    'unit_price' => $line[2],
                ], $orderLines),
            ], $operatorId);

            if ($cancelled) {
                $orderService->cancel($orderId);
            }
        }
    }

    // --- Viaggi (solo se non ce n'è ancora nessuno) ---------------------------------------------
    // Si conferma il piano del primo giorno da pianificare (il Giro Nord) con lo stesso TripService
    // dell'applicazione: nella demo c'è così un viaggio confermato, con i bancali prenotati e le
    // richieste pianificate, e resta un giorno ancora da pianificare (il Giro Sud, con merce che manca).
    // Un bancale del viaggio viene poi bloccato "a mano", così la demo mostra anche le scelte manuali.
    if ($firstId('SELECT id FROM trips LIMIT 1', []) === null) {
        $tripService = new TripService(
            $database,
            new PlanningRepository($database),
            new TripRepository($database),
            new PalletRepository($database),
            new OrderRepository($database),
            new NumberSequenceRepository($database),
            new PalletTypeService(new PalletTypeRepository($database)),
            new SettingService(new SettingRepository($database), $database)
        );

        $days = $tripService->planningDays();
        if ($days !== []) {
            $plan = $tripService->plan($days[0]->date);

            if ($plan->hasTrips()) {
                $tripIds = $tripService->confirm($plan->date, $plan->fingerprint(), $operatorId);

                $items = $tripService->itemsOf($tripIds[0]);
                if ($items !== []) {
                    $tripService->setPalletLock($tripIds[0], $items[0]->palletId, true, $operatorId);
                }
            }
        }
    }

    // --- Dati dell'azienda sui documenti (solo se l'admin non li ha ancora scritti) ---------------
    // Dati finti ma plausibili: compaiono nell'intestazione dei PDF della demo. Il codice non ha
    // nessun valore predefinito: in produzione li imposta l'admin da /settings.
    $settingService = new SettingService(new SettingRepository($database), $database);
    if ($settingService->get(SettingService::COMPANY_NAME) === '') {
        $settingRepository = new SettingRepository($database);
        foreach ([
            SettingService::COMPANY_NAME => 'Ortofrutta Demo S.r.l.',
            SettingService::COMPANY_ADDRESS => 'Via dei Magazzini 10, 09100 Cagliari (CA)',
            SettingService::COMPANY_VAT => 'IT00000000000',
            SettingService::COMPANY_FOOTER => 'Documento di esempio con dati dimostrativi, generato da LogiDock.',
        ] as $name => $value) {
            $settingRepository->save($name, $value, $userIds['admin']);
        }
    }

    // --- Un viaggio già partito, con i suoi documenti (solo se non ce n'è ancora nessuno) ---------
    // Una richiesta con consegna oggi: si pianifica, si conferma e si fa partire il viaggio con gli stessi
    // servizi dell'applicazione. Così la demo ha un viaggio "In viaggio" con bancali spediti, movimenti
    // di uscita e i tre tipi di documento (carico, DDT, compenso), accanto al viaggio ancora modificabile.
    if ($firstId("SELECT id FROM trips WHERE status IN ('in_transit', 'completed') LIMIT 1", []) === null) {
        $orderRepository = new OrderRepository($database);
        $sequences = new NumberSequenceRepository($database);
        $bianchiId = $firstId('SELECT id FROM customers WHERE name = ?', ['Alimentari Bianchi']);

        if ($bianchiId !== null) {
            $orderService = new OrderService(
                $database,
                $orderRepository,
                $sequences,
                new CustomerService(new CustomerRepository($database), new RoundService(new RoundRepository($database))),
                new ProductService(new ProductRepository($database))
            );
            $today = $inDays(0);

            $orderService->create([
                'customer_id' => (string) $bianchiId,
                'requested_date' => $today,
                'delivery_date' => $today,
                'notes' => 'Ritiro in giornata.',
                'lines' => [
                    ['product_id' => (string) $productIds['PAS-500'], 'quantity' => '50', 'unit_price' => '11.20'],
                    ['product_id' => (string) $productIds['SCA-PIA'], 'quantity' => '120', 'unit_price' => '0.95'],
                ],
            ], $operatorId);

            $tripRepository = new TripRepository($database);
            $palletRepository = new PalletRepository($database);
            $settings = new SettingService(new SettingRepository($database), $database);
            $tripService = new TripService(
                $database,
                new PlanningRepository($database),
                $tripRepository,
                $palletRepository,
                $orderRepository,
                $sequences,
                new PalletTypeService(new PalletTypeRepository($database)),
                $settings
            );
            $departureService = new TripDepartureService(
                $database,
                $tripRepository,
                $palletRepository,
                new PalletMovementRepository($database),
                $orderRepository,
                new DocumentService(
                    $database,
                    new TransportDocumentRepository($database),
                    $sequences,
                    $settings,
                    new DocumentRenderer()
                )
            );

            $plan = $tripService->plan($today);
            if ($plan->hasTrips()) {
                foreach ($tripService->confirm($today, $plan->fingerprint(), $operatorId) as $tripId) {
                    $departureService->depart($tripId, $operatorId);
                }
            }
        }
    }
};
