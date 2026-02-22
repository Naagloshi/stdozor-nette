import { Controller } from '@hotwired/stimulus';
import { base64urlToBuffer, bufferToBase64url } from '../lib/webauthn-utils.js';

/**
 * WebAuthn Login Controller (Passkey authentication).
 * Handles passwordless login using discoverable credentials (passkeys).
 */
export default class extends Controller {
    static values = {
        optionsUrl: String,
        verifyUrl: String,
        errorCancelled: String,
        errorFailed: String,
    };
    static targets = ['button', 'status', 'error'];

    async login() {
        this.showStatus();

        try {
            // 1. Get assertion options from server
            const optionsResponse = await fetch(this.optionsUrlValue, {
                method: 'POST',
            });
            if (!optionsResponse.ok) {
                throw new Error('Failed to get login options');
            }

            const options = await optionsResponse.json();

            // 2. Decode challenge from base64url
            options.challenge = base64urlToBuffer(options.challenge);

            if (options.allowCredentials) {
                options.allowCredentials = options.allowCredentials.map(cred => ({
                    ...cred,
                    id: base64urlToBuffer(cred.id),
                }));
            }

            // 3. Call WebAuthn API (browser will prompt user)
            const assertion = await navigator.credentials.get({ publicKey: options });

            // 4. Encode response for server
            const assertionResponse = assertion.response;
            const credentialData = {
                id: assertion.id,
                rawId: bufferToBase64url(assertion.rawId),
                type: assertion.type,
                response: {
                    clientDataJSON: bufferToBase64url(assertionResponse.clientDataJSON),
                    authenticatorData: bufferToBase64url(assertionResponse.authenticatorData),
                    signature: bufferToBase64url(assertionResponse.signature),
                },
            };

            if (assertionResponse.userHandle) {
                credentialData.response.userHandle = bufferToBase64url(assertionResponse.userHandle);
            }

            // 5. Send to server for verification
            const loginResponse = await fetch(this.verifyUrlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify(credentialData),
            });

            const result = await loginResponse.json();

            if (loginResponse.ok && result.success) {
                window.location.href = result.redirect || '/projekty';
            } else {
                throw new Error(result.error || this.errorFailedValue);
            }

        } catch (error) {
            if (error.name === 'NotAllowedError') {
                this.showError(this.errorCancelledValue);
            } else {
                this.showError(error.message || this.errorFailedValue);
            }
        }
    }

    showStatus() {
        this.buttonTarget.classList.add('hidden');
        this.statusTarget.classList.remove('hidden');
        this.errorTarget.classList.add('hidden');
    }

    showError(message) {
        this.buttonTarget.classList.remove('hidden');
        this.statusTarget.classList.add('hidden');
        this.errorTarget.classList.remove('hidden');
        this.errorTarget.textContent = message;
    }
}
