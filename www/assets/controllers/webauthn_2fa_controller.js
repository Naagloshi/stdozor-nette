import { Controller } from '@hotwired/stimulus';
import { base64urlToBuffer, bufferToBase64url } from '../lib/webauthn-utils.js';

/**
 * WebAuthn 2FA Controller.
 * Handles 2FA verification using security keys during login.
 * Fetches assertion options from server, then verifies via JSON API.
 */
export default class extends Controller {
    static values = {
        optionsUrl: String,
        verifyUrl: String,
        touchPrompt: String,
        cancelledMessage: String,
        errorMessage: String,
    };
    static targets = ['status', 'button', 'error', 'trusted'];

    connect() {
        // Auto-trigger authentication on page load
        this.authenticate();
    }

    async authenticate() {
        this.buttonTarget.disabled = true;
        this.errorTarget.classList.add('hidden');
        this.statusTarget.innerHTML = `
            <div class="animate-pulse flex flex-col items-center">
                <svg class="w-10 h-10 text-blue-500 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                <p>${this.touchPromptValue}</p>
            </div>
        `;

        try {
            // 1. Get assertion options from server
            const optionsResponse = await fetch(this.optionsUrlValue, {
                method: 'POST',
            });
            if (!optionsResponse.ok) {
                throw new Error('Failed to get 2FA options');
            }

            const requestData = await optionsResponse.json();

            // Decode challenge
            requestData.challenge = base64urlToBuffer(requestData.challenge);

            // Decode allowCredentials
            if (requestData.allowCredentials) {
                requestData.allowCredentials = requestData.allowCredentials.map(cred => ({
                    ...cred,
                    id: base64urlToBuffer(cred.id),
                }));
            }

            // 2. Call WebAuthn API
            const credential = await navigator.credentials.get({ publicKey: requestData });

            // 3. Encode response
            const credentialData = {
                id: credential.id,
                rawId: bufferToBase64url(credential.rawId),
                type: credential.type,
                response: {
                    authenticatorData: bufferToBase64url(credential.response.authenticatorData),
                    clientDataJSON: bufferToBase64url(credential.response.clientDataJSON),
                    signature: bufferToBase64url(credential.response.signature),
                },
            };

            if (credential.response.userHandle) {
                credentialData.response.userHandle = bufferToBase64url(credential.response.userHandle);
            }

            // 4. Send to server
            const trusted = this.hasTrustedTarget && this.trustedTarget.checked;
            const verifyResponse = await fetch(this.verifyUrlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    credential: credentialData,
                    trusted: trusted,
                }),
            });

            const result = await verifyResponse.json();

            if (verifyResponse.ok && result.success) {
                window.location.href = result.redirect || '/projekty';
            } else {
                throw new Error(result.error || this.errorMessageValue);
            }

        } catch (error) {
            this.buttonTarget.disabled = false;
            if (error.name === 'NotAllowedError') {
                this.statusTarget.innerHTML = `
                    <div class="flex flex-col items-center text-gray-600">
                        <svg class="w-10 h-10 text-gray-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                        <p>${this.cancelledMessageValue}</p>
                    </div>
                `;
            } else {
                this.errorTarget.classList.remove('hidden');
                this.errorTarget.textContent = error.message || this.errorMessageValue;
                this.statusTarget.innerHTML = '';
            }
        }
    }
}
