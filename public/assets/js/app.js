// JavaScript vanilla, poco e solo dove serve.

// 1) Conferma prima di azioni delicate. Uso: <form data-confirm="Sei sicuro?"> ... </form>
document.addEventListener('submit', (event) => {
    const message = event.target.dataset.confirm;

    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});

// 2) Form di arrivo: aggiungere e togliere righe (un bancale per riga).
//    La riga nuova è una copia del <template id="line-template">, dove "__INDEX__" diventa il suo numero.
//    Senza JavaScript il form funziona lo stesso, con le righe già mostrate dal server.
const linesBody = document.querySelector('[data-lines]');
const lineTemplate = document.getElementById('line-template');

if (linesBody && lineTemplate) {
    let nextIndex = Number(linesBody.dataset.nextIndex || linesBody.children.length);

    document.querySelector('[data-add-line]')?.addEventListener('click', () => {
        linesBody.insertAdjacentHTML('beforeend', lineTemplate.innerHTML.replaceAll('__INDEX__', String(nextIndex)));
        nextIndex += 1;
    });

    linesBody.addEventListener('click', (event) => {
        const button = event.target.closest('[data-remove-line]');

        // Una riga resta sempre: toglierla tutta non avrebbe senso.
        if (button && linesBody.children.length > 1) {
            button.closest('tr').remove();
        }
    });
}


// 3) Schede (impostazioni): i riquadri diventano schede WAI-ARIA "Tabs" con le frecce, Home e Fine.
//    Senza JavaScript, o se i pezzi non tornano, restano tutti visibili uno sotto l'altro e il form si salva lo stesso.
//    La scheda aperta sta nell'indirizzo (#accesso) e anche nell'action del form: dopo il salvataggio il browser
//    eredita il frammento dal reindirizzamento e si torna sulla stessa scheda.
document.querySelectorAll('[data-tabs]').forEach((root) => {
    const tablist = root.querySelector('[role="tablist"]');
    const tabs = [...root.querySelectorAll('[role="tab"]')];
    const panels = [...root.querySelectorAll('[data-tab-panel]')];

    if (!tablist || tabs.length === 0 || tabs.length !== panels.length) {
        return;
    }

    const form = root.closest('form');
    const baseAction = form ? form.getAttribute('action').split('#')[0] : null;
    const idOf = (tab) => tab.id.replace(/^tab-/, '');
    const panelOf = (tab) => document.getElementById(tab.getAttribute('aria-controls'));

    // L'action del form porta il frammento della scheda aperta: dopo il salvataggio si torna sulla stessa scheda.
    const syncAction = (tab) => {
        if (form && baseAction) {
            form.setAttribute('action', baseAction + '#' + idOf(tab));
        }
    };

    const select = (tab, { focus = false, updateUrl = true } = {}) => {
        tabs.forEach((other) => {
            const active = other === tab;

            other.setAttribute('aria-selected', String(active));
            other.tabIndex = active ? 0 : -1;
            panelOf(other).hidden = !active;
        });

        if (focus) {
            tab.focus();
        }

        if (updateUrl) {
            history.replaceState(null, '', '#' + idOf(tab));
        }

        syncAction(tab);
    };

    panels.forEach((panel) => {
        panel.setAttribute('role', 'tabpanel');
        panel.setAttribute('aria-labelledby', 'tab-' + panel.id.replace(/^panel-/, ''));
        // Il titolo del riquadro c'è già nella scheda: resta per chi usa uno screen reader, ma non si vede due volte.
        panel.querySelector('h2')?.classList.add('visually-hidden');
    });
    tablist.hidden = false;

    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => select(tab));
        tab.addEventListener('keydown', (event) => {
            const target = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: tabs.length - 1 }[event.key];

            if (target === undefined) {
                return;
            }

            event.preventDefault();
            select(tabs[(target + tabs.length) % tabs.length], { focus: true });
        });
    });

    window.addEventListener('hashchange', () => {
        const tab = tabs.find((candidate) => '#' + idOf(candidate) === location.hash);

        if (tab) {
            select(tab, { updateUrl: false });
        }
    });

    // Prima scheda aperta: la prima con un errore (l'utente deve vederlo), altrimenti quella dell'indirizzo, altrimenti la prima.
    const withError = tabs.find((tab) => tab.classList.contains('has-error'));
    const fromHash = tabs.find((tab) => '#' + idOf(tab) === location.hash);

    select(withError ?? fromHash ?? tabs[0], { updateUrl: Boolean(withError ?? fromHash) });
});
