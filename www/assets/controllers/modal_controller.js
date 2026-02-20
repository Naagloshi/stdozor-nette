import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller pro modal dialog (Tailwind).
 *
 * Použití:
 * <div data-controller="modal">
 *     <button data-action="click->modal#open">Open Modal</button>
 *
 *     <div data-modal-target="container" class="hidden">
 *         <div data-modal-target="backdrop"></div>
 *         <div data-modal-target="dialog">
 *             <button data-action="click->modal#close">Close</button>
 *         </div>
 *     </div>
 * </div>
 */
export default class extends Controller {
    static targets = ['container', 'backdrop', 'dialog'];

    connect() {
        // Zavřít modal při Escape
        this.handleEscape = this.handleEscape.bind(this);
    }

    open(event) {
        if (event) event.preventDefault();

        this.containerTarget.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        document.addEventListener('keydown', this.handleEscape);

        // Fade in animace
        setTimeout(() => {
            this.backdropTarget.style.opacity = '1';
            this.dialogTarget.style.opacity = '1';
            this.dialogTarget.style.transform = 'scale(1)';
        }, 10);
    }

    close(event) {
        if (event) event.preventDefault();

        // Fade out animace
        this.backdropTarget.style.opacity = '0';
        this.dialogTarget.style.opacity = '0';
        this.dialogTarget.style.transform = 'scale(0.95)';

        setTimeout(() => {
            this.containerTarget.classList.add('hidden');
            document.body.style.overflow = '';
            document.removeEventListener('keydown', this.handleEscape);
        }, 200);
    }

    closeOnBackdrop(event) {
        if (event.target === this.backdropTarget) {
            this.close();
        }
    }

    handleEscape(event) {
        if (event.key === 'Escape') {
            this.close();
        }
    }

    disconnect() {
        document.removeEventListener('keydown', this.handleEscape);
        document.body.style.overflow = '';
    }
}
