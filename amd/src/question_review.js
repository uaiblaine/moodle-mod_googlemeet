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
 * Teacher review of AI draft questions: selection and bulk publish/discard.
 *
 * @module     mod_googlemeet/question_review
 * @copyright  2026 PreparaOposiciones
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import * as Notification from 'core/notification';
import {getStrings} from 'core/str';
import {reloadHub} from 'mod_googlemeet/recording_hub';

const COMPONENT = 'mod_googlemeet';

const stringKeys = [
    'question_bulk_publish_count',
    'question_select_all',
    'question_deselect_all',
    'question_bulk_discard',
    'question_bulk_discard_confirm',
    'question_publishing',
    'question_published_count',
    'question_publish_batch_failed',
    'question_publish_activity',
    'question_publish_activity_confirm',
    'question_publish_activity_partial',
    'question_publish_activity_none',
    'question_publish_recording_failed',
    'question_reload',
    'question_publish_recording',
    'question_publish_recording_confirm',
    'ai_error_unknown',
];

let initialised = false;
let settings = {};
const strings = {};

/**
 * Replace {$a} in a language string.
 *
 * @param {string} text String with a {$a} placeholder.
 * @param {string|number} value Replacement.
 * @returns {string}
 */
const fill = (text, value) => String(text || '').split('{$a}').join(String(value));

/**
 * Escape text for safe insertion into HTML strings.
 *
 * @param {string} text Raw text.
 * @returns {string}
 */
const escapeHtml = text => {
    const holder = document.createElement('div');
    holder.textContent = text || '';
    return holder.innerHTML;
};

/**
 * Every element of the page matching a selector.
 *
 * @param {string} selector CSS selector.
 * @returns {HTMLElement[]}
 */
const all = selector => Array.from(document.querySelectorAll(selector));

/**
 * Handle events on elements matching a selector, delegated from the document.
 *
 * A click on a disabled match is ignored.
 *
 * @param {string} type Event type.
 * @param {string} selector CSS selector.
 * @param {Function} handler Called with the matching element and the event.
 * @returns {void}
 */
const delegate = (type, selector, handler) => {
    document.addEventListener(type, event => {
        const element = event.target instanceof Element ? event.target.closest(selector) : null;
        if (element && !(type === 'click' && element.disabled === true)) {
            handler(element, event);
        }
    });
};

/**
 * Selected draft question checkboxes.
 *
 * @returns {HTMLInputElement[]}
 */
const selectedDrafts = () => all('.googlemeet-question-select[data-isdraft="1"]:checked');

/**
 * IDs of the given checkboxes.
 *
 * @param {HTMLInputElement[]} boxes Checkboxes.
 * @returns {number[]}
 */
const idsOf = boxes => boxes.map(box => parseInt(box.value, 10));

/**
 * Show a status message under the bulk bar.
 *
 * @param {string} type Bootstrap alert type (info, success, danger, warning).
 * @param {string} html Message HTML (already escaped).
 * @returns {void}
 */
const showStatus = (type, html) => {
    all('.googlemeet-question-bulk-status').forEach(element => {
        element.setAttribute('class', 'googlemeet-question-bulk-status alert alert-' + type + ' mt-2 mb-3');
        element.innerHTML = html;
    });
};

/**
 * Show a "working" status with a spinner.
 *
 * @returns {void}
 */
const showWorking = () => {
    showStatus('info', '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>' +
        escapeHtml(strings.question_publishing));
};

/**
 * Disable/enable every bulk action while a request is in flight.
 *
 * @param {boolean} busy Whether a request is running.
 * @returns {void}
 */
const setBusy = busy => {
    settings.busy = busy;
    all('.googlemeet-question-bulkbar button, .googlemeet-question-publish-activity').forEach(button => {
        button.disabled = busy;
    });
    if (!busy) {
        refreshState();
    }
};

/**
 * Sync the select-all box and the selected-count labels with the current selection.
 *
 * @returns {void}
 */
const refreshState = () => {
    const boxes = all('.googlemeet-question-select');
    const checked = boxes.filter(box => box.checked);
    const drafts = selectedDrafts().length;

    all('.googlemeet-question-select-all').forEach(selectAll => {
        selectAll.checked = boxes.length > 0 && checked.length === boxes.length;
        selectAll.indeterminate = checked.length > 0 && checked.length < boxes.length;
    });
    // Once everything is ticked the same box clears the selection: say so in its label.
    const allChecked = boxes.length > 0 && checked.length === boxes.length;
    const label = allChecked ? strings.question_deselect_all : strings.question_select_all;
    if (label) {
        all('label[for="googlemeet-question-select-all"]').forEach(element => {
            element.textContent = label;
        });
    }

    if (settings.busy) {
        return;
    }
    all('.googlemeet-question-bulk-publish').forEach(button => {
        button.textContent = fill(strings.question_bulk_publish_count, drafts);
        button.disabled = drafts === 0;
    });
    all('.googlemeet-question-bulk-discard').forEach(button => {
        button.disabled = drafts === 0;
    });
};

/**
 * Message for a failed all-or-nothing batch.
 *
 * @param {Object} error Ajax error.
 * @returns {string}
 */
const batchError = error => escapeHtml(strings.question_publish_batch_failed) +
    '<div class="small mt-1">' + escapeHtml(error.message || error.error || strings.ai_error_unknown) + '</div>';

/**
 * Publish a batch of draft questions of this recording (all-or-nothing).
 *
 * @param {number[]} ids Question IDs.
 * @returns {void}
 */
const publishBatch = ids => {
    if (!ids.length || settings.busy) {
        return;
    }
    setBusy(true);
    showWorking();
    Ajax.call([{
        methodname: 'mod_googlemeet_publish_questions',
        args: {
            recordingid: settings.recordingid,
            coursemoduleid: settings.cmid,
            questionids: ids,
        },
    }])[0].then(response => {
        showStatus('success', escapeHtml(fill(strings.question_published_count, response.count)));
        setTimeout(reloadHub, 900);
        return;
    }).catch(error => {
        setBusy(false);
        showStatus('danger', batchError(error));
    });
};

/**
 * Render the per-recording outcome of an activity-wide publish.
 *
 * @param {Object} response WS response.
 * @returns {void}
 */
const showActivityOutcome = response => {
    const failed = (response.results || []).filter(result => !result.success);
    if (!failed.length) {
        const message = response.published > 0
            ? fill(strings.question_published_count, response.published)
            : strings.question_publish_activity_none;
        showStatus(response.published > 0 ? 'success' : 'info', escapeHtml(message));
        if (response.published > 0) {
            setTimeout(reloadHub, 900);
        }
        return;
    }

    let html = '<div class="fw-bold">' + escapeHtml(fill(strings.question_publish_activity_partial, response.published)) +
        '</div><ul class="mb-2 mt-1">';
    failed.forEach(result => {
        html += '<li>' + escapeHtml(fill(strings.question_publish_recording_failed, result.count)) + ' <strong>' +
            escapeHtml(result.name) + '</strong><div class="small">' + escapeHtml(result.error) + '</div></li>';
    });
    html += '</ul><button type="button" class="btn btn-sm btn-outline-secondary googlemeet-question-reload">' +
        escapeHtml(strings.question_reload) + '</button>';
    showStatus(response.published > 0 ? 'warning' : 'danger', html);
};

/**
 * Publish every draft of the activity (one all-or-nothing batch per recording).
 *
 * @returns {void}
 */
const publishActivity = () => {
    if (settings.busy) {
        return;
    }
    setBusy(true);
    showWorking();
    Ajax.call([{
        methodname: 'mod_googlemeet_publish_activity_drafts',
        args: {
            coursemoduleid: settings.cmid,
        },
    }])[0].then(response => {
        setBusy(false);
        showActivityOutcome(response);
        return;
    }).catch(error => {
        setBusy(false);
        showStatus('danger', escapeHtml(error.message || strings.ai_error_unknown));
    });
};

/**
 * Bind the bulk bar events.
 *
 * @returns {void}
 */
const bindEvents = () => {
    delegate('change', '.googlemeet-question-select-all', selectAll => {
        all('.googlemeet-question-select').forEach(box => {
            box.checked = selectAll.checked;
        });
        refreshState();
    });
    delegate('change', '.googlemeet-question-select', refreshState);

    delegate('click', '.googlemeet-question-publish', button => {
        const card = button.closest('.googlemeet-question-card');
        publishBatch([parseInt(card ? card.getAttribute('data-questionid') : undefined, 10)]);
    });

    delegate('click', '.googlemeet-question-bulk-publish', () => publishBatch(idsOf(selectedDrafts())));

    delegate('click', '.googlemeet-question-publish-recording', button => {
        const ids = idsOf(all('.googlemeet-question-select[data-isdraft="1"]'));
        if (!ids.length) {
            return;
        }
        Notification.saveCancelPromise(
            strings.question_publish_recording,
            escapeHtml(fill(strings.question_publish_recording_confirm, ids.length)),
            strings.question_publish_recording,
            {triggerElement: button}
        ).then(() => publishBatch(ids)).catch(() => null);
    });

    delegate('click', '.googlemeet-question-bulk-discard', button => {
        const ids = idsOf(selectedDrafts());
        if (!ids.length) {
            return;
        }
        // A rejection before the confirmation is the dialogue being cancelled: nothing to report.
        let confirmed = false;
        Notification.saveCancelPromise(
            strings.question_bulk_discard,
            escapeHtml(strings.question_bulk_discard_confirm),
            strings.question_bulk_discard,
            {triggerElement: button}
        ).then(() => {
            confirmed = true;
            setBusy(true);
            return Ajax.call([{
                methodname: 'mod_googlemeet_discard_questions',
                args: {
                    recordingid: settings.recordingid,
                    coursemoduleid: settings.cmid,
                    questionids: ids,
                },
            }])[0];
        }).then(reloadHub).catch(error => {
            if (confirmed) {
                setBusy(false);
                showStatus('danger', escapeHtml(error.message || strings.ai_error_unknown));
            }
            return null;
        });
    });

    delegate('click', '.googlemeet-question-publish-activity', button => {
        const count = parseInt(button.getAttribute('data-count'), 10) || 0;
        Notification.saveCancelPromise(
            strings.question_publish_activity,
            escapeHtml(fill(strings.question_publish_activity_confirm, count)),
            strings.question_publish_activity,
            {triggerElement: button}
        ).then(publishActivity).catch(() => null);
    });

    delegate('click', '.googlemeet-question-reload', () => reloadHub());
};

/**
 * Initialise the question review bulk actions.
 *
 * @param {Object} config Configuration.
 * @param {number} config.cmid Course module ID.
 * @param {number} config.recordingid Recording ID.
 * @returns {void}
 */
export const init = config => {
    if (initialised) {
        return;
    }
    initialised = true;
    settings = {
        cmid: parseInt(config.cmid, 10),
        recordingid: parseInt(config.recordingid, 10),
        busy: false,
    };

    getStrings(stringKeys.map(key => ({key: key, component: COMPONENT}))).then(values => {
        stringKeys.forEach((key, index) => {
            strings[key] = values[index];
        });
        bindEvents();
        refreshState();
        return null;
    }).catch(Notification.exception);
};
