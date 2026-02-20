import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller pro user menu (Tailwind navbar).
 *
 * Použití:
 * <div data-controller="user-menu">
 *     <button data-action="click->user-menu#toggle">User Menu</button>
 *     <div data-user-menu-target="menu" class="hidden">...</div>
 * </div>
 */
export default class extends Controller {
    static targets = ['menu'];

    toggle(event) {
        // Zastavit propagaci aby se nezavolal handleOutsideClick
        event.stopPropagation();

        const isHidden = this.menuTarget.classList.contains('hidden');
        this.menuTarget.classList.toggle('hidden');

        // Přidat outside click listener jen když se otevírá
        if (isHidden) {
            // Použít setTimeout aby se listener přidal až po dokončení current eventu
            setTimeout(() => {
                document.addEventListener('click', this.boundHandleOutsideClick);
            }, 0);
        } else {
            document.removeEventListener('click', this.boundHandleOutsideClick);
        }
    }

    connect() {
        this.boundHandleOutsideClick = this.handleOutsideClick.bind(this);
    }

    disconnect() {
        document.removeEventListener('click', this.boundHandleOutsideClick);
    }

    handleOutsideClick(event) {
        if (!this.element.contains(event.target)) {
            this.menuTarget.classList.add('hidden');
            document.removeEventListener('click', this.boundHandleOutsideClick);
        }
    }
}
