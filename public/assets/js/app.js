// JavaScript vanilla: per ora serve solo a chiedere conferma prima di azioni delicate.
// Uso: <form data-confirm="Sei sicuro?"> ... </form>
document.addEventListener('submit', (event) => {
    const message = event.target.dataset.confirm;

    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});
