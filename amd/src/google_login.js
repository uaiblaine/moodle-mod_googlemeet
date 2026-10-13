// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Google account login button: opens the OAuth login URL in a popup window.
 *
 * The URL comes from the button's data-loginurl attribute, never from inline script, so no URL value
 * is interpolated into executable JavaScript. It must stay a window.open() popup: callback.php
 * reloads the opener and closes the popup. Rendered by mod_googlemeet\client::print_login_popup().
 *
 * @module     mod_googlemeet/google_login
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @type {string} Features of the login popup window. */
const POPUP_FEATURES = 'height=600,width=599,top=0,left=0,menubar=0,location=0,directories=0,fullscreen=0';

/**
 * Open the login popup when the button is clicked.
 *
 * @param {string} buttonid Id of the login button (unique per rendering).
 */
export const init = buttonid => {
    const button = document.getElementById(buttonid);
    if (!button) {
        return;
    }
    button.addEventListener('click', e => {
        e.preventDefault();
        window.open(button.getAttribute('data-loginurl'), 'Login', POPUP_FEATURES);
    });
};
