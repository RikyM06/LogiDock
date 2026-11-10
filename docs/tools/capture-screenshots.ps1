# Rigenera gli screenshot del sito vetrina (docs/assets/screenshots/*.png).
#
# Uso (dalla radice del progetto, con MySQL acceso e il seed già eseguito):
#   powershell -ExecutionPolicy Bypass -File docs/tools/capture-screenshots.ps1
#
# Come funziona:
#   1. avvia un server PHP temporaneo sull'applicazione (database di sviluppo, dati demo del seed);
#   2. entra come admin@logidock.test, con la password SEED_ADMIN_PASSWORD letta da .env
#      (la password non è scritta qui e non viene stampata);
#   3. apre Edge (o Chrome) "headless", cioè senza finestra, e lo comanda con il protocollo
#      DevTools: va su ogni pagina, aspetta che sia caricata e salva lo screenshot in PNG;
#   4. alla fine chiude browser e server e cancella il profilo temporaneo del browser.
# Gli screenshot mostrano solo l'applicazione: niente schede, preferiti o dati personali.

param(
    [int] $Port = 8077,
    [int] $DebugPort = 9339,
    [int] $Width = 1440,
    [int] $Height = 900
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$outDir = Join-Path $root 'docs\assets\screenshots'
$base = "http://localhost:$Port"
New-Item -ItemType Directory -Force $outDir | Out-Null

# --- Password dell'utente di esempio, da .env -----------------------------------------------------
$envFile = Join-Path $root '.env'
$adminPassword = ''
if (Test-Path $envFile) {
    foreach ($line in Get-Content $envFile) {
        if ($line -match '^\s*SEED_ADMIN_PASSWORD\s*=\s*(.*)$') {
            $adminPassword = $matches[1].Trim().Trim('"').Trim("'")
        }
    }
}
if ($adminPassword -eq '') {
    throw 'SEED_ADMIN_PASSWORD manca in .env: serve per entrare con l''utente di esempio.'
}

# --- Browser: Edge, altrimenti Chrome --------------------------------------------------------------
$browser = @(
    'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
    'C:\Program Files\Microsoft\Edge\Application\msedge.exe',
    'C:\Program Files\Google\Chrome\Application\chrome.exe',
    'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe'
) | Where-Object { Test-Path $_ } | Select-Object -First 1
if ($null -eq $browser) {
    throw 'Non trovo né Edge né Chrome.'
}

$php = $null
$edge = $null
$socket = $null
$profileDir = Join-Path $env:TEMP ('logidock-screenshots-' + [guid]::NewGuid().ToString('N'))

try {
    # --- 1. Server PHP temporaneo ------------------------------------------------------------------
    $php = Start-Process php -ArgumentList '-S', "localhost:$Port", '-t', "$root\public" `
        -WorkingDirectory $root -WindowStyle Hidden -PassThru
    $ready = $false
    for ($i = 0; $i -lt 50 -and -not $ready; $i++) {
        Start-Sleep -Milliseconds 200
        try { Invoke-WebRequest "$base/login" -UseBasicParsing -TimeoutSec 2 | Out-Null; $ready = $true } catch { }
    }
    if (-not $ready) { throw "Il server PHP non risponde su $base." }

    # --- 2. Login (fuori dal browser) e cookie di sessione --------------------------------------
    $web = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $loginPage = Invoke-WebRequest "$base/login" -WebSession $web -UseBasicParsing
    if ($loginPage.Content -notmatch 'name="_csrf" value="([^"]+)"') { throw 'Token CSRF non trovato nella pagina di login.' }
    $afterLogin = Invoke-WebRequest "$base/login" -Method Post -WebSession $web -UseBasicParsing -Body @{
        _csrf = $matches[1]; email = 'admin@logidock.test'; password = $adminPassword
    }
    if ($afterLogin.Content -notmatch 'action="/logout"') {
        throw 'Login non riuscito: controlla SEED_ADMIN_PASSWORD e di aver eseguito php bin/seed.php.'
    }
    $cookie = $web.Cookies.GetCookies([Uri] "$base/") | Where-Object { $_.Name -eq 'logidock_session' } | Select-Object -First 1
    if ($null -eq $cookie) { throw 'Cookie di sessione non trovato dopo il login.' }

    # --- 3. Browser headless comandato con il protocollo DevTools -----------------------------------
    $edge = Start-Process $browser -PassThru -WindowStyle Hidden -ArgumentList @(
        '--headless=new', '--disable-gpu', '--hide-scrollbars', '--no-first-run', '--no-default-browser-check',
        "--remote-debugging-port=$DebugPort", "--user-data-dir=$profileDir", "--window-size=$Width,$Height", 'about:blank'
    )
    $target = $null
    for ($i = 0; $i -lt 50 -and $null -eq $target; $i++) {
        Start-Sleep -Milliseconds 200
        try {
            # In PowerShell 5.1 un array JSON arriva come un oggetto solo: prima in una variabile, poi si filtra.
            $targets = Invoke-RestMethod "http://127.0.0.1:$DebugPort/json/list" -TimeoutSec 2
            $target = $targets | Where-Object { $_.type -eq 'page' } | Select-Object -First 1
        } catch { }
    }
    if ($null -eq $target) { throw 'Il browser non ha aperto la porta di debug.' }

    $socket = New-Object System.Net.WebSockets.ClientWebSocket
    $socket.ConnectAsync([Uri] $target.webSocketDebuggerUrl, [Threading.CancellationToken]::None).Wait()
    $script:messageId = 0

    # Manda un comando DevTools e aspetta la sua risposta (gli eventi intanto si ignorano).
    function Invoke-Cdp([string] $method, [hashtable] $params = @{}) {
        $script:messageId++
        $json = @{ id = $script:messageId; method = $method; params = $params } | ConvertTo-Json -Depth 10 -Compress
        $bytes = [Text.Encoding]::UTF8.GetBytes($json)
        $socket.SendAsync([ArraySegment[byte]]::new($bytes), 'Text', $true, [Threading.CancellationToken]::None).Wait()

        while ($true) {
            $stream = New-Object IO.MemoryStream
            do {
                $buffer = New-Object byte[] 65536
                $result = $socket.ReceiveAsync([ArraySegment[byte]]::new($buffer), [Threading.CancellationToken]::None).GetAwaiter().GetResult()
                $stream.Write($buffer, 0, $result.Count)
            } while (-not $result.EndOfMessage)

            $message = [Text.Encoding]::UTF8.GetString($stream.ToArray()) | ConvertFrom-Json
            if ($message.PSObject.Properties['id'] -and $message.id -eq $script:messageId) {
                if ($message.PSObject.Properties['error']) { throw "DevTools $method : $($message.error.message)" }
                return $message.result
            }
        }
    }

    function Invoke-Js([string] $expression) {
        $response = Invoke-Cdp 'Runtime.evaluate' @{ expression = $expression; returnByValue = $true; awaitPromise = $true }
        if ($response.PSObject.Properties['exceptionDetails']) { throw "Errore JavaScript: $($response.exceptionDetails.text)" }
        return $response.result.value
    }

    # Va a una pagina e aspetta che sia caricata del tutto.
    function Open-Page([string] $path) {
        Invoke-Cdp 'Page.navigate' @{ url = "$base$path" } | Out-Null
        $expected = ($path -split '\?')[0]
        for ($i = 0; $i -lt 50; $i++) {
            Start-Sleep -Milliseconds 200
            if ((Invoke-Js 'document.readyState + "|" + location.pathname') -eq "complete|$expected") {
                Start-Sleep -Milliseconds 300
                return
            }
        }
        throw "La pagina $path non si è caricata."
    }

    # $scrollTo (facoltativo): selettore CSS di un elemento più in basso da portare in cima prima della foto.
    function Save-Screenshot([string] $name, [string] $scrollTo = '') {
        if ($scrollTo -eq '') {
            Invoke-Js 'window.scrollTo(0, 0); true' | Out-Null
            $shot = Invoke-Cdp 'Page.captureScreenshot' @{
                format = 'png'
                clip = @{ x = 0; y = 0; width = $Width; height = $Height; scale = 1 }
            }
        } else {
            if (-not (Invoke-Js "(() => { const el = document.querySelector('$scrollTo'); if (el) { el.scrollIntoView(); } return !!el; })()")) {
                throw "Elemento $scrollTo non trovato."
            }
            Start-Sleep -Milliseconds 300
            # Senza "clip" si fotografa la parte visibile della pagina, cioè quella appena scorsa.
            $shot = Invoke-Cdp 'Page.captureScreenshot' @{ format = 'png' }
        }
        $file = Join-Path $outDir "$name.png"
        [IO.File]::WriteAllBytes($file, [Convert]::FromBase64String($shot.data))
        Write-Host ("  {0,-22} {1,8:N0} byte" -f "$name.png", (Get-Item $file).Length)
    }

    Invoke-Cdp 'Page.enable' | Out-Null
    Invoke-Cdp 'Network.enable' | Out-Null
    Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{ width = $Width; height = $Height; deviceScaleFactor = 1; mobile = $false } | Out-Null
    Invoke-Cdp 'Network.setCookie' @{
        name = 'logidock_session'; value = $cookie.Value; domain = 'localhost'; path = '/'; httpOnly = $true
    } | Out-Null

    # --- 4. Le pagine da fotografare ----------------------------------------------------------------
    Write-Host "Screenshot in $outDir"

    # Home con l'avviso dei bancali fermi; giacenze per prodotto; lista dei soli bancali fermi.
    Open-Page '/';             Save-Screenshot 'home'
    Open-Page '/stock';        Save-Screenshot 'giacenze'
    Open-Page '/stock?aging=1'; Save-Screenshot 'giacenze-bancali' '#bancali'

    Open-Page '/products';     Save-Screenshot 'prodotti'
    Open-Page '/master-data';  Save-Screenshot 'anagrafiche'
    Open-Page '/customers';    Save-Screenshot 'clienti'
    Open-Page '/inbound';      Save-Screenshot 'arrivi'

    # Dettaglio: il primo arrivo della lista (quello del seed).
    $detail = Invoke-Js 'var a = document.querySelector("tbody a[href^=\"/inbound/\"]"); a ? a.getAttribute("href") : ""'
    if ($detail -eq '') { throw 'Nessun arrivo in lista: hai eseguito php bin/seed.php?' }
    Open-Page $detail;         Save-Screenshot 'arrivo-dettaglio'

    # Nuovo arrivo: il form compilato con dati di esempio, SENZA inviarlo (non crea niente).
    Open-Page '/inbound/create'
    Invoke-Js @'
(() => {
    const pick = (name, text) => {
        const select = document.querySelector(`[name="${name}"]`);
        const option = [...select.options].find((o) => o.text.includes(text));
        if (option) { select.value = option.value; }
    };
    const put = (name, value) => { document.querySelector(`[name="${name}"]`).value = value; };
    const day = (offset) => new Date(Date.now() + offset * 86400000).toISOString().slice(0, 10);

    pick('supplier_id', 'Sorgenti Alpine');
    put('number', 'DDT ' + new Date().getFullYear() + '/0201');
    put('document_date', day(-1));
    put('received_date', day(0));
    [0, 1].forEach((i) => {
        pick(`lines[${i}][product_id]`, 'ACQ-050');
        pick(`lines[${i}][pallet_type_id]`, 'EPAL 1');
        put(`lines[${i}][quantity]`, '120');
        put(`lines[${i}][unit_price]`, '3,10');
        put(`lines[${i}][recorded_weight_kg]`, i === 0 ? '1515' : '1512');
        put(`lines[${i}][lot_number]`, 'L2611B');
        put(`lines[${i}][expiry_date]`, day(300));
        pick(`lines[${i}][location_id]`, 'A-01-03');
    });
    return true;
})()
'@ | Out-Null
    Save-Screenshot 'arrivo-nuovo'

    # Richieste dei clienti: lista, dettaglio di quella con l'avviso di giacenza (Emporio del Borgo), form compilato.
    Open-Page '/orders';       Save-Screenshot 'richieste'
    $order = Invoke-Js @'
(() => {
    const row = [...document.querySelectorAll('tbody tr')].find((tr) => tr.textContent.includes('Emporio del Borgo'));
    const link = row ? row.querySelector('a[href^="/orders/"]') : null;
    return link ? link.getAttribute('href') : '';
})()
'@
    if ($order -eq '') { throw 'Richiesta demo non trovata: hai eseguito php bin/seed.php?' }
    Open-Page $order;          Save-Screenshot 'richiesta-dettaglio'

    Open-Page '/orders/create'
    Invoke-Js @'
(() => {
    const pick = (name, text) => {
        const select = document.querySelector(`[name="${name}"]`);
        const option = [...select.options].find((o) => o.text.includes(text));
        if (option) { select.value = option.value; }
    };
    const put = (name, value) => { document.querySelector(`[name="${name}"]`).value = value; };
    const day = (offset) => new Date(Date.now() + offset * 86400000).toISOString().slice(0, 10);

    pick('customer_id', 'Alimentari Bianchi');
    put('delivery_date', day(4));
    put('notes', 'Chiamare prima della consegna.');
    [['PAS-500', '24', '11,50'], ['OLI-1L', '6', '38,00']].forEach(([product, quantity, price], i) => {
        pick(`lines[${i}][product_id]`, product);
        put(`lines[${i}][quantity]`, quantity);
        put(`lines[${i}][unit_price]`, price);
    });
    return true;
})()
'@ | Out-Null
    Save-Screenshot 'richiesta-nuova'

    # Viaggi: lista, piano del primo giorno ancora da pianificare (solo calcolo: non conferma niente)
    # e dettaglio del viaggio confermato dal seed.
    Open-Page '/trips';        Save-Screenshot 'viaggi'
    $plan = Invoke-Js @'
(() => {
    const link = document.querySelector('tbody a[href^="/trips/plan?date="]');
    return link ? link.getAttribute('href') : '';
})()
'@
    $trip = Invoke-Js @'
(() => {
    const link = document.querySelector('tbody a[href^="/trips/"]:not([href^="/trips/plan"])');
    return link ? link.getAttribute('href') : '';
})()
'@
    if ($plan -eq '' -or $trip -eq '') { throw 'Giorno da pianificare o viaggio demo non trovato: hai eseguito php bin/seed.php?' }
    Open-Page $plan;           Save-Screenshot 'viaggio-piano'
    Save-Screenshot 'viaggio-piano-richieste' '#richieste'
    Open-Page $trip;           Save-Screenshot 'viaggio-dettaglio'

    # Scheda fornitore inviata vuota: mostra che tutti i campi sono obbligatori (non salva niente).
    Open-Page '/suppliers/create'
    Invoke-Js 'document.querySelector("form.form").submit(); true' | Out-Null
    $shown = $false
    for ($i = 0; $i -lt 50 -and -not $shown; $i++) {
        Start-Sleep -Milliseconds 200
        # Durante il cambio di pagina il browser può non rispondere: si riprova al giro dopo.
        try { $shown = [bool] (Invoke-Js 'document.readyState === "complete" && document.querySelectorAll(".form__error").length > 0') } catch { }
    }
    if (-not $shown) { throw 'Il form del fornitore non ha mostrato gli errori.' }
    Start-Sleep -Milliseconds 300
    Save-Screenshot 'fornitore-obbligatori'

    Open-Page '/settings';     Save-Screenshot 'impostazioni'

    # Gestione utenti: lista e form di un nuovo utente (le password non si compilano mai).
    Open-Page '/users';        Save-Screenshot 'utenti'
    Open-Page '/users/create'
    Invoke-Js @'
(() => {
    document.querySelector('[name="email"]').value = 'nuovo.operatore@logidock.test';
    document.querySelector('[name="name"]').value = 'Nuovo Operatore';
    document.querySelector('[name="role"]').value = 'operatore';
    return true;
})()
'@ | Out-Null
    Save-Screenshot 'utente-nuovo'

    Invoke-Cdp 'Browser.close' | Out-Null
} finally {
    if ($null -ne $socket) { $socket.Dispose() }
    if ($null -ne $edge -and -not $edge.HasExited) { Stop-Process -Id $edge.Id -Force -ErrorAction SilentlyContinue }
    if ($null -ne $php -and -not $php.HasExited) { Stop-Process -Id $php.Id -Force -ErrorAction SilentlyContinue }
    Start-Sleep -Milliseconds 500
    Remove-Item -Recurse -Force $profileDir -ErrorAction SilentlyContinue
}
