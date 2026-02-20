import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller pro mobile menu toggle (Tailwind navbar).
 *
 * Použití:
 * <nav data-controller="mobile-menu">
 *     <button data-action="click->mobile-menu#toggle">Menu</button>
 *     <div data-mobile-menu-target="menu" class="hidden">...</div>
 * </nav>
 */
export default class extends Controller {
    static targets = ['menu'];

    toggle() {
        this.menuTarget.classList.toggle('hidden');
    }
}
