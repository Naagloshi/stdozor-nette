import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller pro Tailwind toast notifikace.
 *
 * Použití:
 * <div data-controller="toast">
 *     <div data-toast-target="notification">...</div>
 * </div>
 */
export default class extends Controller {
    static targets = ['notification'];

    connect() {
        // Auto-hide všechny notifikace po 5 sekundách
        this.notificationTargets.forEach(notification => {
            setTimeout(() => {
                this.fadeOut(notification);
            }, 5000);
        });
    }

    close(event) {
        const notification = event.currentTarget.closest('[data-toast-target="notification"]');
        this.fadeOut(notification);
    }

    fadeOut(element) {
        element.style.transition = 'opacity 0.3s ease-out, transform 0.3s ease-out';
        element.style.opacity = '0';
        element.style.transform = 'translateX(100%)';

        setTimeout(() => {
            element.remove();
        }, 300);
    }
}
