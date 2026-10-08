<?php

declare(strict_types=1);

?>

<section
    class="passkey-panel"
    aria-labelledby="passkeys-title"
    data-passkey-panel
    hidden
>
    <div class="passkey-panel__heading">

        <div>
            <p class="passkey-panel__eyebrow">
                MÉTODOS DE ACCESO
            </p>

            <h2 id="passkeys-title">
                Passkeys
            </h2>

            <p class="passkey-panel__description">
                Inicia sesión de forma segura sin escribir tu contraseña.
                Puedes utilizar Windows Hello, PIN, rostro, huella,
                una llave de seguridad u otro dispositivo compatible.
            </p>
        </div>

        <button
            type="button"
            class="device-secondary"
            data-passkey-enroll
            hidden
        >
            Registrar nueva passkey
        </button>

    </div>

    <p
        class="passkey-compatibility"
        data-passkey-compatibility
    ></p>

    <div
        class="passkey-list"
        data-passkey-list
        aria-live="polite"
    ></div>

    <p
        data-passkey-message
        role="status"
        aria-live="polite"
    ></p>

</section>


<dialog
    class="device-dialog"
    data-passkey-dialog
    aria-labelledby="passkey-dialog-title"
>
    <h2
        id="passkey-dialog-title"
        data-passkey-dialog-title
    >
        Registrar una passkey
    </h2>

    <p data-passkey-dialog-help>
        Registra este dispositivo como un método seguro
        para iniciar sesión en SoporteGR ERP.
    </p>

    <form>

        <div
            class="passkey-name-field"
            data-passkey-name-row
        >
            <label for="passkey-name">
                Nombre de esta passkey
            </label>

            <input
                id="passkey-name"
                data-passkey-name
                type="text"
                autocomplete="off"
                maxlength="80"
                placeholder="Ej. PC oficina · Windows Hello"
            >

            <small>
                Usa un nombre que te permita reconocer
                fácilmente dónde registraste esta passkey.
            </small>
        </div>

        <label for="passkey-password">
            Contraseña actual del ERP
        </label>

        <input
            id="passkey-password"
            data-passkey-password
            type="password"
            autocomplete="current-password"
            required
            maxlength="1024"
        >

        <small>
            Por seguridad, confirma tu contraseña antes
            de registrar un nuevo método de acceso.
        </small>

        <p
            data-passkey-message
            role="status"
            aria-live="polite"
        ></p>

        <div class="device-dialog-actions">

            <button
                type="submit"
                class="device-secondary"
            >
                Continuar
            </button>

            <button
                type="button"
                class="device-secondary"
                data-passkey-cancel
            >
                Cancelar
            </button>

        </div>

    </form>

</dialog>