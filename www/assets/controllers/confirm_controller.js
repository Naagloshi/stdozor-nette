import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller pro potvrzovací dialog před destruktivními akcemi.
 * Používá sdílený #confirm-modal v layoutu.
 *
 * Použití:
 * <a href="{link delete!, $id}"
 *    data-controller="confirm"
 *    data-confirm-message-value="Opravdu chcete smazat?"
 *    data-action="click->confirm#ask"
 *    class="ajax ...">
 *    Smazat
 * </a>
 */
export default class extends Controller {
    static values = {
        message: String,
    };

    ask(event) {
        event.preventDefault();
        event.stopPropagation();

        const url = this.element.getAttribute('href');
        if (!url) return;

        const modal = document.getElementById('confirm-modal');
        const backdrop = document.getElementById('confirm-modal-backdrop');
        const dialog = document.getElementById('confirm-modal-dialog');
        const message = document.getElementById('confirm-modal-message');
        const confirmBtn = document.getElementById('confirm-modal-confirm');
        const cancelBtn = document.getElementById('confirm-modal-cancel');

        if (!modal || !message || !confirmBtn || !cancelBtn) return;

        // Naplnit zprávu
        message.textContent = this.messageValue;

        // Zobrazit modal
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';

        // Animace
        setTimeout(() => {
            backdrop.style.opacity = '1';
            dialog.style.opacity = '1';
            dialog.style.transform = 'scale(1)';
        }, 10);

        // Escape klávesa
        const handleEscape = (e) => {
            if (e.key === 'Escape') closeModal();
        };
        document.addEventListener('keydown', handleEscape);

        // Backdrop klik
        const handleBackdropClick = (e) => {
            if (e.target === backdrop) closeModal();
        };
        backdrop.addEventListener('click', handleBackdropClick);

        const closeModal = () => {
            backdrop.style.opacity = '0';
            dialog.style.opacity = '0';
            dialog.style.transform = 'scale(0.95)';

            setTimeout(() => {
                modal.classList.add('hidden');
                document.body.style.overflow = '';
            }, 200);

            document.removeEventListener('keydown', handleEscape);
            backdrop.removeEventListener('click', handleBackdropClick);
            confirmBtn.replaceWith(confirmBtn.cloneNode(true));
            cancelBtn.replaceWith(cancelBtn.cloneNode(true));
        };

        // Cancel
        cancelBtn.addEventListener('click', closeModal, { once: true });

        // Confirm — provést AJAX request přes Naja
        confirmBtn.addEventListener('click', () => {
            closeModal();

            // Naja AJAX request
            if (window.naja) {
                window.naja.makeRequest('GET', url, null, {
                    history: false,
                });
            } else {
                // Fallback bez Naja
                window.location.href = url;
            }
        }, { once: true });
    }
}
