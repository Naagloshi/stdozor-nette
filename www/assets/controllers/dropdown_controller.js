import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller pro dropdown menu.
 *
 * Použití:
 * <div data-controller="dropdown" class="relative">
 *     <button data-action="click->dropdown#toggle">⋮</button>
 *     <div data-dropdown-target="menu" class="hidden absolute right-0 ...">
 *         <a href="...">Action</a>
 *     </div>
 * </div>
 */
export default class extends Controller {
    static targets = ['menu'];

    connect() {
        this.boundHandleOutsideClick = this.handleOutsideClick.bind(this);
        this.boundHandleEscape = this.handleEscape.bind(this);
    }

    toggle(event) {
        event.stopPropagation();

        const isHidden = this.menuTarget.classList.contains('hidden');

        // Zavřít všechny ostatní otevřené dropdowny
        document.querySelectorAll('[data-dropdown-target="menu"]').forEach(menu => {
            if (menu !== this.menuTarget) {
                menu.classList.add('hidden');
            }
        });

        this.menuTarget.classList.toggle('hidden');

        if (isHidden) {
            setTimeout(() => {
                document.addEventListener('click', this.boundHandleOutsideClick);
                document.addEventListener('keydown', this.boundHandleEscape);
            }, 0);
        } else {
            this.removeListeners();
        }
    }

    close() {
        this.menuTarget.classList.add('hidden');
        this.removeListeners();
    }

    handleOutsideClick(event) {
        if (!this.element.contains(event.target)) {
            this.close();
        }
    }

    handleEscape(event) {
        if (event.key === 'Escape') {
            this.close();
        }
    }

    removeListeners() {
        document.removeEventListener('click', this.boundHandleOutsideClick);
        document.removeEventListener('keydown', this.boundHandleEscape);
    }

    disconnect() {
        this.removeListeners();
    }
}
