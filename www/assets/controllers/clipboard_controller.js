import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { text: String };
    static targets = ['label', 'icon'];

    async copy() {
        try {
            await navigator.clipboard.writeText(this.textValue);
            this.showCopied();
        } catch {
            // Fallback for older browsers / non-HTTPS
            this._fallbackCopy(this.textValue);
            this.showCopied();
        }
    }

    showCopied() {
        const originalLabel = this.labelTarget.textContent;
        const originalIcon = this.iconTarget.innerHTML;

        this.labelTarget.textContent = this.labelTarget.dataset.copiedText || 'Zkopírováno!';
        this.iconTarget.innerHTML = '<svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>';

        setTimeout(() => {
            this.labelTarget.textContent = originalLabel;
            this.iconTarget.innerHTML = originalIcon;
        }, 2000);
    }

    _fallbackCopy(text) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
    }
}
