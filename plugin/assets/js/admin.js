/**
 * BillMySales for Dolibarr.
 *
 * Copyright (c) 2026 BillMySales <https://www.billmysales.com>
 * Licensed under the GNU Affero General Public License v3.0 or later.
 * See LICENSE file for more details.
 */

/**
 * Settings page: show/hide the secret.
 */
(function () {
    'use strict';

    /**
     * Adds a button next to the secret field that shows or hides it (the
     * settings form has no HTML for it: the field is a plain password
     * input).
     *
     * @return {void}
     */
    function initSecretToggle() {
        var input = document.getElementById('BILLMYSALES_TOKEN');
        if (!input || !input.parentNode) {
            return;
        }
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'button';
        button.textContent = '\u{1F441}';
        button.addEventListener('click', function () {
            input.type = input.type === 'password' ? 'text' : 'password';
        });
        input.parentNode.insertBefore(button, input.nextSibling);
    }

    document.addEventListener('DOMContentLoaded', initSecretToggle);
})();
