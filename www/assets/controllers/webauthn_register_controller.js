import { Controller } from '@hotwired/stimulus';
import { base64urlToBuffer, bufferToBase64url } from '../lib/webauthn-utils.js';

/**
 * WebAuthn Registration Controller.
 * Handles registration of new WebAuthn credentials (security keys + passkeys).
 */
export default class extends Controller {
    static targets = ['name', 'status', 'error', 'success', 'buttons'];
    static values = {
        optionsUrl: String,
        completeUrl: String,
        redirectUrl: String,
        isPasskey: String,
        errorCancelled: String,
        errorFailed: String,
    };

    async register() {
        const name = this.nameTarget.value.trim();
        if (!name) {
            this.nameTarget.focus();
            return;
        }

        this.showStatus();

        try {
            // 1. Get registration options from server
            const optionsResponse = await fetch(this.optionsUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ isPasskey: this.isPasskeyValue === 'true' }),
            });

            if (!optionsResponse.ok) {
                throw new Error('Failed to get registration options');
            }

            const options = await optionsResponse.json();

            // 2. Decode challenge and user.id from base64url
            options.challenge = base64urlToBuffer(options.challenge);
            options.user.id = base64urlToBuffer(options.user.id);

            if (options.excludeCredentials) {
                options.excludeCredentials = options.excludeCredentials.map(cred => ({
                    ...cred,
                    id: base64urlToBuffer(cred.id),
                }));
            }

            // 3. Call WebAuthn API
            const credential = await navigator.credentials.create({ publicKey: options });

            // 4. Encode response for server
            const attestationResponse = credential.response;
            const credentialData = {
                id: credential.id,
                rawId: bufferToBase64url(credential.rawId),
                type: credential.type,
                response: {
                    clientDataJSON: bufferToBase64url(attestationResponse.clientDataJSON),
                    attestationObject: bufferToBase64url(attestationResponse.attestationObject),
                },
            };

            if (attestationResponse.getTransports) {
                credentialData.response.transports = attestationResponse.getTransports();
            }

            // 5. Send to server
            const completeResponse = await fetch(this.completeUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    name: name,
                    isPasskey: this.isPasskeyValue === 'true',
                    credential: credentialData,
                }),
            });

            if (!completeResponse.ok) {
                const data = await completeResponse.json();
                throw new Error(data.error || this.errorFailedValue);
            }

            // 6. Success
            this.showSuccess();
            setTimeout(() => {
                window.location.href = this.redirectUrlValue;
            }, 1500);

        } catch (error) {
            if (error.name === 'NotAllowedError') {
                this.showError(this.errorCancelledValue);
            } else {
                this.showError(error.message || this.errorFailedValue);
            }
        }
    }

    showStatus() {
        this.statusTarget.classList.remove('hidden');
        this.buttonsTarget.classList.add('hidden');
        this.errorTarget.classList.add('hidden');
        this.successTarget.classList.add('hidden');
    }

    showError(message) {
        this.statusTarget.classList.add('hidden');
        this.buttonsTarget.classList.remove('hidden');
        this.errorTarget.classList.remove('hidden');
        this.errorTarget.textContent = message;
    }

    showSuccess() {
        this.statusTarget.classList.add('hidden');
        this.buttonsTarget.classList.add('hidden');
        this.successTarget.classList.remove('hidden');
    }
}
