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
 * Recording sync helpers.
 *
 * @module     mod_googlemeet/sync
 * @copyright  2026 PreparaOposiciones
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {getString} from 'core/str';
import Notification from 'core/notification';

let initialised = false;
let countdownTimer = null;

/** @var {WeakSet<HTMLFormElement>} Sync forms already submitted once. */
const submittingForms = new WeakSet();

/**
 * Format seconds remaining as a compact countdown.
 *
 * @param {number} seconds Seconds remaining.
 * @returns {string}
 */
const formatCountdown = seconds => {
    seconds = Math.max(0, Math.floor(seconds));
    if (seconds < 3600) {
        const minutes = Math.floor(seconds / 60);
        const secs = seconds % 60;
        return minutes + ':' + String(secs).padStart(2, '0');
    }
    if (seconds < 86400) {
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        return hours + 'h ' + minutes + 'm';
    }
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    return days + 'd ' + hours + 'h';
};

/**
 * Promote a room CTA from soon to live when its countdown reaches zero.
 *
 * @param {HTMLElement} countdown Countdown element inside the CTA.
 * @returns {void}
 */
const refreshRoomCtaOnLive = countdown => {
    const cta = countdown.closest('.googlemeet-room-cta');
    if (!cta || cta.getAttribute('data-googlemeet-room-live') === '1') {
        return;
    }

    const liveLabel = cta.getAttribute('data-live-label') || '';
    if (liveLabel !== '') {
        cta.querySelectorAll('.googlemeet-room-cta-label').forEach(label => {
            label.textContent = liveLabel;
        });
    }
    cta.classList.remove('googlemeet-room-cta-soon');
    cta.classList.add('googlemeet-room-cta-live');
    if (!cta.querySelector('.googlemeet-pulse')) {
        const pulse = document.createElement('span');
        pulse.classList.add('googlemeet-pulse');
        pulse.setAttribute('aria-hidden', 'true');
        cta.prepend(pulse);
    }
    countdown.textContent = '';
    countdown.setAttribute('hidden', 'hidden');
    cta.setAttribute('data-googlemeet-room-live', '1');
};

/**
 * Refresh all countdown elements on the page.
 *
 * @returns {void}
 */
const refreshCountdowns = () => {
    document.querySelectorAll('[data-googlemeet-countdown]').forEach(element => {
        const target = parseInt(element.getAttribute('data-target-ts'), 10);
        if (!target) {
            return;
        }

        const remaining = target - Math.floor(Date.now() / 1000);
        if (remaining <= 0) {
            if (element.classList.contains('googlemeet-room-cta-countdown')) {
                refreshRoomCtaOnLive(element);
                return;
            }

            const expired = element.getAttribute('data-countdown-expired') || '';
            if (expired !== '') {
                element.textContent = expired;
            }
            return;
        }

        const prefix = element.getAttribute('data-countdown-prefix') || '';
        const value = formatCountdown(remaining);
        element.textContent = prefix !== '' ? prefix + ' ' + value : value;
    });
};

/**
 * Initialise live countdown labels for room and upcoming-event states.
 *
 * @returns {void}
 */
const initCountdowns = () => {
    if (!document.querySelector('[data-googlemeet-countdown]')) {
        return;
    }

    refreshCountdowns();
    if (countdownTimer === null) {
        countdownTimer = window.setInterval(refreshCountdowns, 1000);
    }
};

/**
 * Initialise sync overlay and topic filter behaviours.
 *
 * @returns {void}
 */
export const init = () => {
    if (initialised) {
        return;
    }
    initialised = true;

    document.addEventListener('submit', event => {
        const form = event.target instanceof Element ? event.target.closest('form') : null;
        if (!form) {
            return;
        }
        const method = (form.getAttribute('method') || '').toLowerCase();
        const action = form.getAttribute('action') || '';
        const isSyncForm = method === 'post' && (
            form.querySelector('input[name="sync"]') || action.indexOf('sync=') !== -1
        );

        if (!isSyncForm) {
            return;
        }
        if (form.closest('[data-googlemeet-async-sync]')) {
            // Handled in the background by initSyncPanel().
            return;
        }

        if (submittingForms.has(form)) {
            event.preventDefault();
            event.stopPropagation();
            return;
        }

        submittingForms.add(form);
        const overlay = document.getElementById('googlemeet_syncimg');
        if (overlay) {
            overlay.style.display = 'flex';
        }
        form.querySelectorAll('input[type="submit"], button[type="submit"], button:not([type])').forEach(button => {
            button.disabled = true;
        });
    });

    const topicSelect = document.getElementById('googlemeet-topic-select');
    if (topicSelect) {
        topicSelect.addEventListener('change', () => {
            topicSelect.form.submit();
        });
    }

    initCountdowns();
};

/** @var {number} Poll interval while a background sync is queued/running (ms). */
const POLL_MS = 4000;

let syncPanelInitialised = false;
let pollTimer = null;

/**
 * Render a sync status in the panel.
 *
 * @param {Object} status Result of mod_googlemeet_get_sync_status.
 * @returns {void}
 */
const renderSyncStatus = status => {
    const regions = document.querySelectorAll('[data-region="googlemeet-sync-progress"]');
    if (!regions.length) {
        return;
    }
    if (status.status === 'none' || !status.message) {
        regions.forEach(region => region.setAttribute('hidden', 'hidden'));
        return;
    }
    regions.forEach(region => {
        region.removeAttribute('hidden');
        region.classList.toggle('alert-info', !!status.active);
        region.classList.toggle('alert-success', status.status === 'success');
        region.classList.toggle('alert-danger', status.status === 'error');
        region.querySelectorAll('[data-region="googlemeet-sync-progress-text"]').forEach(text => {
            text.textContent = status.message;
        });
        region.querySelectorAll('[data-action="googlemeet-sync-reload"]').forEach(link => {
            link.hidden = !(status.status === 'success' && status.haschanges);
        });
    });
    if (status.lastsync) {
        const lastsync = document.getElementById('id_lastsync');
        if (lastsync) {
            lastsync.textContent = status.lastsync;
        }
    }
    document.querySelectorAll('[data-googlemeet-async-sync] button, [data-googlemeet-async-sync] input[type="submit"]')
        .forEach(button => {
            button.disabled = !!status.active;
        });
};

/**
 * Poll the sync status until it is no longer queued/running.
 *
 * @param {number} cmid Course module id.
 * @returns {void}
 */
const pollSyncStatus = cmid => {
    window.clearTimeout(pollTimer);
    Ajax.call([{methodname: 'mod_googlemeet_get_sync_status', args: {coursemoduleid: cmid}}])[0]
        .then(status => {
            renderSyncStatus(status);
            if (status.active) {
                pollTimer = window.setTimeout(() => pollSyncStatus(cmid), POLL_MS);
            }
            return status;
        })
        .catch(() => {
            // Transient network error: keep trying, more slowly.
            pollTimer = window.setTimeout(() => pollSyncStatus(cmid), POLL_MS * 3);
        });
};

/**
 * Background "Sync with Google Drive" button: queue, then poll and show the result without reloading.
 *
 * @param {number} cmid Course module id.
 * @param {boolean} active A sync is already queued/running on page load.
 * @returns {void}
 */
export const initSyncPanel = (cmid, active) => {
    if (syncPanelInitialised) {
        return;
    }
    syncPanelInitialised = true;

    document.addEventListener('submit', event => {
        const form = event.target instanceof Element ? event.target.closest('[data-googlemeet-async-sync] form') : null;
        if (!form) {
            return;
        }
        event.preventDefault();
        const buttons = Array.from(form.querySelectorAll('button, input[type="submit"]'));
        // The state of the first button stands for all of them.
        if (buttons.length && buttons[0].disabled) {
            return;
        }
        const setDisabled = disabled => buttons.forEach(button => {
            button.disabled = disabled;
        });
        setDisabled(true);
        getString('sync_status_requesting', 'mod_googlemeet').then(text => {
            renderSyncStatus({status: 'queued', active: true, message: text, haschanges: false, lastsync: ''});
            return text;
        }).catch(Notification.exception);

        Ajax.call([{methodname: 'mod_googlemeet_request_sync', args: {coursemoduleid: cmid}}])[0]
            .then(status => {
                renderSyncStatus(status);
                if (status.active) {
                    pollTimer = window.setTimeout(() => pollSyncStatus(cmid), POLL_MS);
                }
                return status;
            })
            .catch(error => {
                setDisabled(false);
                Notification.exception(error);
            });
    });

    document.addEventListener('click', event => {
        const link = event.target instanceof Element ? event.target.closest('[data-action="googlemeet-sync-reload"]') : null;
        if (!link) {
            return;
        }
        event.preventDefault();
        window.location.reload();
    });

    if (active) {
        pollSyncStatus(cmid);
    }
};
