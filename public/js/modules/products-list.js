(function () {
    'use strict';

    document.querySelectorAll('[data-product-thumbnail]').forEach(function (wrapper) {
        var image = wrapper.querySelector('[data-product-thumbnail-image]');
        var placeholder = wrapper.querySelector('.product-thumbnail-placeholder');

        if (!image || !placeholder) {
            return;
        }

        image.addEventListener('load', function () {
            image.hidden = false;
            placeholder.hidden = true;
            placeholder.setAttribute('aria-hidden', 'true');
            wrapper.classList.remove('is-broken');
        }, { once: true });

        image.addEventListener('error', function () {
            image.hidden = true;
            placeholder.hidden = false;
            placeholder.setAttribute('aria-hidden', 'false');
            wrapper.classList.add('is-broken');
        }, { once: true });
    });
}());
