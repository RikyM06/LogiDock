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
