'use strict';
window.GRWebAuthn = Object.freeze({
    available() {
        return Promise.resolve(Boolean(
            window.isSecureContext
            && window.PublicKeyCredential
            && navigator.credentials
            && typeof navigator.credentials.create === 'function'
            && typeof navigator.credentials.get === 'function'
        ));
    },
});
