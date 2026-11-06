<?php

declare(strict_types=1);

use LogiDock\Core\Database;

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

    // --- Fornitori (nomi di fantasia) -----------------------------------------------------------
    $supplierIds = [];
    $suppliers = [
        ['Sorgenti Alpine S.r.l.', 'Via delle Fonti 12, Bergamo'],
        ['Dispensa Italia S.r.l.', 'Via Roma 45, Parma'],
        ['Cartotecnica Lombarda S.r.l.', 'Via dell\'Industria 8, Brescia'],
        ['Igiene Casa Distribuzione S.r.l.', 'Via Mazzini 101, Verona'],
    ];
    foreach ($suppliers as [$name, $address]) {
        $supplierIds[$name] = $firstId('SELECT id FROM suppliers WHERE name = ?', [$name])
            ?? $insert('INSERT INTO suppliers (name, address) VALUES (?, ?)', [$name, $address]);
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

    $trailerTypeId = $firstId('SELECT id FROM trailer_types WHERE name = ?', ['Rimorchio 7,2 m'])
        ?? $insert(
            'INSERT INTO trailer_types (name, length_mm, width_mm, inner_height_mm, max_weight_kg) VALUES (?, ?, ?, ?, ?)',
            ['Rimorchio 7,2 m', 7200, 2450, 2600, '6500.00']
        );
    if ($firstId('SELECT id FROM trailer_types WHERE name = ?', ['Semirimorchio 13,6 m']) === null) {
        $insert(
            'INSERT INTO trailer_types (name, length_mm, width_mm, inner_height_mm, max_weight_kg) VALUES (?, ?, ?, ?, ?)',
            ['Semirimorchio 13,6 m', 13600, 2450, 2700, '24000.00']
        );
    }

    // --- Mezzo ----------------------------------------------------------------------------------
    if ($firstId('SELECT id FROM vehicles WHERE plate = ?', ['FG456HJ']) === null) {
        $insert(
            'INSERT INTO vehicles (plate, description, max_weight_kg, max_pallets, trailer_type_id, carrier_id) VALUES (?, ?, ?, ?, ?, ?)',
            ['FG456HJ', 'Camion 12 bancali', '6500.00', 12, $trailerTypeId, $carrierId]
        );
    }

    // --- Bancali e movimenti --------------------------------------------------------------------
    // [prodotto, fornitore, posizione attuale, quantità, lotto, scadenza (giorni da oggi), arrivato (giorni fa), arrivo in IN-01 poi spostato?]
    $pallets = [
        ['ACQ-050', 'Sorgenti Alpine S.r.l.', 'A-01-01', '120.000', 'L2610A', 300, 20, false],
        ['ACQ-050', 'Sorgenti Alpine S.r.l.', 'A-01-02', '120.000', 'L2610A', 300, 20, true],
        ['PAS-500', 'Dispensa Italia S.r.l.', 'A-02-01', '96.000', 'P2609C', 180, 14, false],
        ['OLI-1L', 'Dispensa Italia S.r.l.', 'B-01-01', '80.000', 'O2608B', 400, 9, false],
        ['SCA-PIA', 'Cartotecnica Lombarda S.r.l.', 'B-01-02', '500.000', null, null, 5, false],
        ['DET-LIQ', 'Igiene Casa Distribuzione S.r.l.', 'A-01-03', '60.000', 'D2610F', 700, 3, false],
        ['PAN-ECO', 'Igiene Casa Distribuzione S.r.l.', 'IN-01', '150.000', 'N2611A', null, 1, false],
    ];

    $operatorId = $userIds['operatore'];
    foreach ($pallets as $index => [$productCode, $supplier, $location, $quantity, $lot, $expiry, $received, $movedAfter]) {
        $code = sprintf('PAL-%d-%06d', $year, $index + 1);
        if ($firstId('SELECT id FROM pallets WHERE code = ?', [$code]) !== null) {
            continue;
        }

        $arrivalLocation = $movedAfter ? 'IN-01' : $location;

        $palletId = $insert(
            'INSERT INTO pallets (code, product_id, supplier_id, location_id, quantity, initial_quantity,
                                  lot_number, expiry_date, received_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $code,
                $productIds[$productCode],
                $supplierIds[$supplier],
                $locationIds[$location],
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
};
