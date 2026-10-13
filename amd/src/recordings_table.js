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
 * Recordings list of the activity page: live filter bar, scroll restore and the teacher row actions.
 *
 * Initialised from mod_googlemeet/recordingstable. The teacher strings arrive in the init call as
 * {{#quote}}-ed literals, so the template keeps owning their escaping.
 *
 * @module     mod_googlemeet/recordings_table
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';

/** @var {number} Pause after the last keystroke before the search is submitted (ms). */
const SEARCH_DEBOUNCE_MS = 600;

/** @var {string} Phone breakpoint: the "Filters" panel starts collapsed below it. */
const PHONE_QUERY = '(max-width: 575.98px)';

/**
 * Live filter bar: search as you type, the "Filters" panel open by breakpoint, topic auto-submit.
 *
 * @param {number} cmid Course module id.
 * @returns {void}
 */
const initFilterBar = cmid => {
    const form = document.getElementById('googlemeet-filter-form-' + cmid);
    if (!form) {
        return;
    }
    const focuskey = 'mod_googlemeet_rq_focus_' + cmid;
    form.classList.add('googlemeet-filterbar-live');
    // The search runs as you type: keep the submit button for keyboard and assistive technology users only.
    const submitbutton = form.querySelector('.googlemeet-filter-submit');
    if (submitbutton) {
        submitbutton.classList.add('visually-hidden');
    }

    // The "Filters" panel: open on desktop, collapsed on phones (the count badge shows what is active).
    const panel = form.querySelector('.googlemeet-filters-panel');
    const phone = window.matchMedia ? window.matchMedia(PHONE_QUERY) : null;
    const syncpanel = () => {
        if (panel && phone) {
            panel.open = !phone.matches;
        }
    };
    syncpanel();
    if (phone && phone.addEventListener) {
        phone.addEventListener('change', syncpanel);
    }

    const submitfilters = keepfocus => {
        if (keepfocus) {
            try {
                sessionStorage.setItem(focuskey, '1');
            } catch (e) {
                // Storage may be blocked; the search still works, only the caret is not restored.
            }
        }
        form.submit();
    };

    // Search as you type: lessons are filtered and paginated server-side, so submit after a pause.
    const input = form.querySelector('input[name="rq"]');
    if (input) {
        let timer = null;
        let submitted = input.value.trim();
        input.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(() => {
                const value = input.value.trim();
                if (value === submitted || value.length === 1) {
                    return;
                }
                submitted = value;
                submitfilters(true);
            }, SEARCH_DEBOUNCE_MS);
        });
        try {
            if (sessionStorage.getItem(focuskey)) {
                sessionStorage.removeItem(focuskey);
                input.focus();
                input.setSelectionRange(input.value.length, input.value.length);
            }
        } catch (e) {
            // Ignore storage failures.
        }
    }
    const topic = form.querySelector('select[name="topic"]');
    if (topic) {
        topic.addEventListener('change', () => {
            submitfilters(false);
        });
    }
};

/**
 * Bring the row a teacher acted on back into view after the reload that follows the action.
 *
 * @param {string} scrollkey Session storage key of the remembered anchor.
 * @returns {void}
 */
const restoreRecordingAnchor = scrollkey => {
    let anchor = '';
    let shouldrestore = false;
    try {
        anchor = sessionStorage.getItem(scrollkey) || '';
        shouldrestore = anchor !== '';
        sessionStorage.removeItem(scrollkey);
    } catch (e) {
        anchor = '';
    }
    if (!anchor && window.location.hash) {
        anchor = window.location.hash.substring(1);
        shouldrestore = anchor !== '';
    }
    if (!shouldrestore) {
        return;
    }
    let target = anchor ? document.getElementById(anchor) : null;
    if (!target) {
        target = document.getElementById('googlemeet_recordings_table');
    }
    if (target) {
        setTimeout(() => {
            target.scrollIntoView({block: 'center'});
        }, 80);
    }
    // Drop the anchor from the URL so a later manual refresh does not jump again.
    if (window.location.hash && window.history && window.history.replaceState) {
        window.history.replaceState(null, '', window.location.pathname + window.location.search);
    }
};

/**
 * Hide an element with an inline display none.
 *
 * @param {HTMLElement|null} element Element.
 * @returns {void}
 */
const hide = element => {
    if (element) {
        element.style.display = 'none';
    }
};

/**
 * Show an element hidden by {@see hide} by clearing the inline display.
 *
 * @param {HTMLElement|null} element Element.
 * @returns {void}
 */
const show = element => {
    if (element) {
        element.style.display = '';
    }
};

/**
 * Wire the teacher row actions: inline rename, show/hide, restore, purge and trash.
 *
 * @param {number} cmid Course module id.
 * @param {Object} strings Lang strings (renamelabel, hidden, purge, purgeconfirm, trash, trashconfirm).
 * @param {Function} reloadRecordingList Reload keeping a recording in view.
 * @returns {void}
 */
const initManagement = (cmid, strings, reloadRecordingList) => {
    /**
     * Leave the inline editor without saving.
     *
     * @param {HTMLInputElement} element The editor input.
     * @returns {void}
     */
    const recordingeditcancel = element => {
        const card = element.closest('.googlemeet-card-title');
        const text = card ? card.querySelector('.recording-name-text') : null;
        element.remove();
        show(text);
    };

    /**
     * Focus left the editor: drop it.
     *
     * @param {FocusEvent} event The focusout event.
     * @returns {void}
     */
    const recordingeditonfocusout = event => {
        recordingeditcancel(event.currentTarget);
    };

    /**
     * Save the new name through the web service and update the row.
     *
     * @param {HTMLInputElement} element The editor input.
     * @returns {void}
     */
    const recordingeditperform = element => {
        const card = element.closest('.googlemeet-card-title');
        const text = card ? card.querySelector('.recording-name-text') : null;

        // The spinner markup is rendered once into a <template> so the pix helper stays out of this script.
        const loading = document.getElementById('googlemeet-inline-loading-template').content.firstElementChild.cloneNode(true);

        if (text) {
            text.textContent = element.value;
            show(text);
        }
        hide(element);
        if (card) {
            card.append(loading);
        }

        Ajax.call([{
            methodname: 'mod_googlemeet_recording_edit_name',
            args: {
                recordingid: element.getAttribute('id'),
                name: element.value,
                coursemoduleid: cmid,
            },
        }])[0].then(response => {
            if (text) {
                text.textContent = response.displayname || response.name;
                show(text);
                text.setAttribute('title', response.name);
                text.setAttribute('data-originalname', response.name);
            }
            document.querySelectorAll('.googlemeet-ai-edit-btn[data-id="' + element.getAttribute('id') + '"]').forEach(btn => {
                btn.setAttribute('data-recordingname', response.name);
            });
            element.remove();
            loading.remove();
            return response;
        }).catch(error => {
            Notification.exception(error);
            if (text) {
                text.textContent = element.getAttribute('data-value');
                show(text);
            }
            element.remove();
            loading.remove();
        });
    };

    /**
     * Enter saves, Escape cancels.
     *
     * @param {KeyboardEvent} event The keydown event.
     * @returns {void}
     */
    const recordingeditkeydown = event => {
        const keyCode = event.which || event.keyCode;
        if (keyCode === 13) {
            event.preventDefault();
            event.currentTarget.removeEventListener('focusout', recordingeditonfocusout);
            recordingeditperform(event.currentTarget);
            return;
        }
        if (keyCode === 27) {
            event.currentTarget.removeEventListener('focusout', recordingeditonfocusout);
            recordingeditcancel(event.currentTarget);
        }
    };

    // Rename (from the row's Actions menu): edit the title inline in its row.
    document.querySelectorAll('.recordingeditname').forEach(button => {
        button.addEventListener('click', () => {
            const recordingid = button.getAttribute('data-id');
            const row = document.getElementById('googlemeet-recording-' + recordingid);
            const card = row ? row.querySelector('.googlemeet-card-title') : null;
            if (!card) {
                return;
            }
            const text = card.querySelector('.recording-name-text');
            const originalname = (text && text.getAttribute('data-originalname')) || (text ? text.textContent.trim() : '');

            hide(text);

            const inputtext = document.createElement('input');
            inputtext.type = 'text';
            inputtext.className = 'form-control googlemeet-edit-input';
            inputtext.setAttribute('aria-label', strings.renamelabel);
            inputtext.id = recordingid;
            inputtext.value = originalname;
            inputtext.setAttribute('data-value', originalname);
            inputtext.addEventListener('keydown', recordingeditkeydown);
            inputtext.addEventListener('focusout', recordingeditonfocusout);

            card.append(inputtext);
            inputtext.focus();
            inputtext.select();
        });
    });

    // Toggle visibility.
    document.querySelectorAll('.recordinghowhide').forEach(btn => {
        btn.addEventListener('click', () => {
            const recordingid = btn.getAttribute('data-id');
            // Works in both views: card grid (.googlemeet-recording-card) and
            // list view (.googlemeet-recording-listitem).
            const card = btn.closest('.googlemeet-recording-card, .googlemeet-recording-listitem');
            const label = btn.querySelector('.googlemeet-action-label');
            const oldLabel = label ? label.textContent : '';

            btn.disabled = true;

            Ajax.call([{
                methodname: 'mod_googlemeet_showhide_recording',
                args: {
                    recordingid: recordingid,
                    coursemoduleid: cmid,
                },
            }])[0].then(response => {
                const visible = response.visible === true || response.visible === 1 ||
                    response.visible === '1' || response.visible === 'true';
                const visibilityLabel = btn.getAttribute(visible ? 'data-label-hide' : 'data-label-show');
                const isListRow = !!card && card.classList.contains('googlemeet-recording-listitem');
                btn.disabled = false;
                if (visibilityLabel && label) {
                    label.textContent = visibilityLabel;
                }
                if (!card) {
                    return response;
                }
                if (visible) {
                    card.classList.remove('googlemeet-recording-hidden');
                    const selector = isListRow ? '.googlemeet-listitem-hidden-tag' : '.googlemeet-recording-hidden-badge';
                    card.querySelectorAll(selector).forEach(tag => tag.remove());
                } else {
                    card.classList.add('googlemeet-recording-hidden');
                    if (isListRow) {
                        if (!card.querySelector('.googlemeet-listitem-hidden-tag')) {
                            const title = card.querySelector('.googlemeet-listitem-title');
                            if (title) {
                                const hiddentag = document.createElement('span');
                                hiddentag.className = 'googlemeet-listitem-hidden-tag';
                                hiddentag.textContent = strings.hidden;
                                title.prepend(hiddentag);
                            }
                        }
                    } else if (!card.querySelector('.googlemeet-recording-hidden-badge')) {
                        const badge = document.createElement('div');
                        badge.className = 'googlemeet-recording-hidden-badge';
                        badge.textContent = strings.hidden;
                        card.prepend(badge);
                    }
                }
                return response;
            }).catch(error => {
                Notification.exception(error);
                btn.disabled = false;
                if (label) {
                    label.textContent = oldLabel;
                }
            });
        });
    });

    document.querySelectorAll('.googlemeet-restore-recording').forEach(btn => {
        btn.addEventListener('click', () => {
            btn.disabled = true;
            Ajax.call([{
                methodname: 'mod_googlemeet_restore_recording',
                args: {
                    recordingid: btn.getAttribute('data-id'),
                    coursemoduleid: cmid,
                },
            }])[0].then(() => {
                reloadRecordingList(btn.getAttribute('data-id'));
                return true;
            }).catch(ex => {
                btn.disabled = false;
                Notification.exception(ex);
            });
        });
    });

    /**
     * Confirm, then call a destructive web service and reload on success.
     *
     * @param {string} selector Button selector.
     * @param {string} methodname Web service.
     * @param {string} title Dialogue title.
     * @param {string} question Dialogue question.
     * @param {string} savelabel Confirm button label.
     * @returns {void}
     */
    const bindConfirmedAction = (selector, methodname, title, question, savelabel) => {
        document.querySelectorAll(selector).forEach(btn => {
            btn.addEventListener('click', () => {
                // A rejection before the confirmation is the dialogue being cancelled: nothing to report.
                let confirmed = false;
                Notification.saveCancelPromise(title, question, savelabel, {triggerElement: btn}).then(() => {
                    confirmed = true;
                    btn.disabled = true;
                    return Ajax.call([{
                        methodname: methodname,
                        args: {
                            recordingid: btn.getAttribute('data-id'),
                            coursemoduleid: cmid,
                        },
                    }])[0];
                }).then(() => {
                    reloadRecordingList(btn.getAttribute('data-id'));
                    return true;
                }).catch(ex => {
                    if (confirmed) {
                        btn.disabled = false;
                        Notification.exception(ex);
                    }
                });
            });
        });
    };

    bindConfirmedAction('.googlemeet-purge-recording', 'mod_googlemeet_purge_recording',
        strings.purge, strings.purgeconfirm, strings.purge);
    bindConfirmedAction('.googlemeet-trash-recording', 'mod_googlemeet_trash_recording',
        strings.trash, strings.trashconfirm, strings.trash);
};

/**
 * Initialise the recordings list.
 *
 * @param {Object} config Configuration.
 * @param {number} config.cmid Course module id.
 * @param {boolean} config.canmanage The viewer gets the teacher row actions.
 * @param {Object} config.strings Lang strings of the teacher actions (empty for students).
 * @returns {void}
 */
export const init = ({cmid, canmanage, strings}) => {
    initFilterBar(cmid);

    const recordingsScrollKey = 'mod_googlemeet_recordings_anchor_' + cmid;

    const rememberRecordingAnchor = recordingid => {
        const anchor = recordingid ? 'googlemeet-recording-' + recordingid : 'googlemeet_recordings_table';
        try {
            sessionStorage.setItem(recordingsScrollKey, anchor);
        } catch (e) {
            // Ignore storage failures; the hash still gives the browser a best-effort anchor.
        }
        window.location.hash = anchor;
    };

    const reloadRecordingList = recordingid => {
        rememberRecordingAnchor(recordingid);
        window.location.reload();
    };

    restoreRecordingAnchor(recordingsScrollKey);

    if (canmanage) {
        initManagement(cmid, strings || {}, reloadRecordingList);
    }
};
