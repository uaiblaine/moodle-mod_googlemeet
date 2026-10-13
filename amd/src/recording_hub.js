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
 * Recording hub interactions.
 *
 * @module     mod_googlemeet/recording_hub
 * @copyright  2026 PreparaOposiciones
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import * as Notification from 'core/notification';
import Modal from 'core/modal';
import {getStrings} from 'core/str';
// Moodle 5.2 and 5.3 both map theme_boost/index to the Bootstrap 5 bundle (5.3 until 7.0).
import {Modal as BootstrapModal, Tab} from 'theme_boost/index';

const COMPONENT = 'mod_googlemeet';

const stringRequests = [
    {key: 'question_generating', component: COMPONENT},
    {key: 'question_unpublish', component: COMPONENT},
    {key: 'question_unpublish_confirm', component: COMPONENT},
    {key: 'question_discard', component: COMPONENT},
    {key: 'question_discard_confirm', component: COMPONENT},
    {key: 'recording_rename', component: COMPONENT},
    {key: 'question_empty_student', component: COMPONENT},
    {key: 'ai_error_unknown', component: COMPONENT},
    {key: 'practice_correct', component: COMPONENT},
    {key: 'practice_incorrect', component: COMPONENT},
    {key: 'practice_correct_answer', component: COMPONENT},
    {key: 'practice_finish', component: COMPONENT},
    {key: 'practice_next', component: COMPONENT},
    {key: 'practice_round_review', component: COMPONENT},
    {key: 'practice_right_option', component: COMPONENT},
    {key: 'practice_your_answer', component: COMPONENT},
    {key: 'practice_live_score', component: COMPONENT},
    {key: 'recording_mark_viewed', component: COMPONENT},
    {key: 'recording_progress_aria', component: COMPONENT},
    {key: 'recording_progress_completed', component: COMPONENT},
    {key: 'recording_progress_partial', component: COMPONENT},
    {key: 'recording_progress_saving', component: COMPONENT},
    {key: 'recording_progress_unseen', component: COMPONENT},
    {key: 'chapter_timestamp_copied', component: COMPONENT},
    {key: 'chapter_timestamp_reference', component: COMPONENT},
    {key: 'chapter_transcript_highlighted', component: COMPONENT},
    {key: 'chapter_seek_announce', component: COMPONENT},
    {key: 'timestamp_seek_aria', component: COMPONENT},
    {key: 'chapter_link_copied', component: COMPONENT},
    {key: 'chapter_link_copy_manual', component: COMPONENT},
    {key: 'transcript_search_count', component: COMPONENT},
    {key: 'transcript_search_none', component: COMPONENT},
    {key: 'transcript_search_match_aria', component: COMPONENT},
    {key: 'name', component: 'core'},
    {key: 'cancel', component: 'core'},
    {key: 'savechanges', component: 'core'},
];

let initialised = false;
let settings = {};
let strings = {};
// The completed flag is measured completion only (what activity completion counts) and is the one
// that stops the heartbeat; markedviewed is the student's own "Mark as viewed". The badge and the
// button show the recording as viewed when either is set (see isViewed()).
let progress = {
    completed: false,
    markedviewed: false,
    watchedseconds: 0,
    thresholdseconds: 480,
    pendingseconds: 0,
    visibleSince: 0,
    timer: null,
};

const HEARTBEAT_INTERVAL_MS = 30000;

/**
 * Load and cache the strings used by this module.
 *
 * @returns {Promise<void>}
 */
const loadStrings = () => getStrings(stringRequests).then(values => {
    stringRequests.forEach((request, index) => {
        strings[request.key] = values[index];
    });
    return;
});

/**
 * The hub root element, or the document when the hub is not on the page.
 *
 * @returns {HTMLElement|Document}
 */
const hubRoot = () => document.getElementById('googlemeet-recording-hub') || document;

/**
 * Every element matching a selector inside a root (the hub by default), in document order.
 *
 * @param {string} selector CSS selector.
 * @param {HTMLElement|Document} [root] Container to search.
 * @returns {HTMLElement[]}
 */
const findAll = (selector, root) => Array.from((root || hubRoot()).querySelectorAll(selector));

/**
 * The first element matching a selector inside a root (the hub by default).
 *
 * @param {string} selector CSS selector.
 * @param {HTMLElement|Document} [root] Container to search.
 * @returns {HTMLElement|null}
 */
const findOne = (selector, root) => (root || hubRoot()).querySelector(selector);

/**
 * Set the text of every element in a list.
 *
 * @param {HTMLElement[]} elements Elements to update.
 * @param {string} text New text.
 * @returns {void}
 */
const setText = (elements, text) => {
    elements.forEach(element => {
        element.textContent = text;
    });
};

/**
 * Read a form control's value by id (undefined when the control is missing).
 *
 * @param {string} id Element id.
 * @returns {string|undefined}
 */
const valueOf = id => {
    const element = document.getElementById(id);
    return element ? element.value : undefined;
};

/**
 * Set a form control's value by id; null and undefined become an empty string.
 *
 * @param {string} id Element id.
 * @param {string|null|undefined} value New value.
 * @returns {void}
 */
const setValue = (id, value) => {
    const element = document.getElementById(id);
    if (element) {
        element.value = (value === null || value === undefined) ? '' : value;
    }
};

/**
 * Escape text for safe insertion into HTML strings.
 *
 * Serialises a text node, so &, < and > (and a no-break space) are escaped and quotes are not.
 *
 * @param {string} value Raw text.
 * @returns {string}
 */
const escapeHtml = value => {
    const holder = document.createElement('div');
    holder.textContent = value || '';
    return holder.innerHTML;
};

/**
 * Show a hub tab button through the Bootstrap 5 Tab component.
 *
 * @param {HTMLElement} tab Tab button.
 * @returns {void}
 */
const showTab = tab => {
    Tab.getOrCreateInstance(tab).show();
};

/**
 * Get the currently active hub tab target.
 *
 * @returns {string}
 */
const activeTabTarget = () => {
    const tab = document.querySelector('#googlemeet-recording-hub .nav-link.active[data-bs-target]');
    return (tab && tab.getAttribute('data-bs-target')) || '';
};

/**
 * Remember active tab and scroll position for a reload.
 *
 * @returns {void}
 */
const rememberHubState = () => {
    try {
        sessionStorage.setItem(settings.hubStateKey, JSON.stringify({
            tab: activeTabTarget(),
            y: window.pageYOffset || document.documentElement.scrollTop || 0,
        }));
    } catch {
        return;
    }
};

/**
 * Reload the hub after preserving tab/scroll state.
 *
 * @returns {void}
 */
export const reloadHub = () => {
    rememberHubState();
    window.location.reload();
};

/**
 * Restore tab and scroll state after a reload.
 *
 * @returns {void}
 */
const restoreHubState = () => {
    let raw = '';
    try {
        raw = sessionStorage.getItem(settings.hubStateKey) || '';
        sessionStorage.removeItem(settings.hubStateKey);
    } catch {
        raw = '';
    }
    if (!raw) {
        return;
    }

    let state = {};
    try {
        state = JSON.parse(raw);
    } catch {
        state = {};
    }

    if (state.tab) {
        const tab = Array.from(document.querySelectorAll('#googlemeet-recording-hub .nav-link[data-bs-target]'))
            .find(link => link.getAttribute('data-bs-target') === state.tab);
        if (tab) {
            showTab(tab);
        }
    }

    setTimeout(() => {
        window.scrollTo(0, parseInt(state.y, 10) || 0);
    }, 80);
};

/**
 * Show a save/cancel confirmation modal.
 *
 * @param {string} title Modal title.
 * @param {string} body Modal body.
 * @param {string} label Save button label.
 * @param {HTMLElement} triggerElement Element opening the modal.
 * @returns {Promise} Resolves when confirmed, rejects when cancelled.
 */
const confirmAction = (title, body, label, triggerElement) =>
    Notification.saveCancelPromise(title, body, label, {triggerElement: triggerElement});

/**
 * Update a recording visibility action after the AJAX toggle response.
 *
 * @param {HTMLElement} button Visibility button.
 * @param {boolean} visible Whether the recording is now visible to students.
 * @returns {void}
 */
const updateVisibilityButton = (button, visible) => {
    const label = button.getAttribute(visible ? 'data-label-hide' : 'data-label-show') || '';
    if (!label) {
        return;
    }

    if (button.textContent.trim()) {
        button.textContent = label;
    }
    if (button.getAttribute('title')) {
        button.setAttribute('title', label);
    }
    if (button.getAttribute('aria-label')) {
        button.setAttribute('aria-label', label);
    }
};

/**
 * Call an external function and reload on success; a failure is reported.
 *
 * @param {string} methodname Web service method name.
 * @param {Object} args Web service arguments.
 * @returns {Promise}
 */
const call = (methodname, args) => Ajax.call([{methodname: methodname, args: args}])[0]
    .then(() => {
        reloadHub();
        return;
    })
    .catch(Notification.exception);

/**
 * Whether the page is currently visible enough to count heartbeat seconds.
 *
 * @returns {boolean}
 */
const pageIsVisible = () => !document.visibilityState || document.visibilityState === 'visible';

/**
 * Whether the student sees the recording as viewed: measured completion or a manual mark.
 *
 * @returns {boolean}
 */
const isViewed = () => progress.completed || progress.markedviewed;

/**
 * Label and percent for the current progress state.
 *
 * @returns {Object}
 */
const currentProgressState = () => {
    let label = strings.recording_progress_unseen;
    let percent = 0;

    if (isViewed()) {
        label = strings.recording_progress_completed;
        percent = 100;
    } else if (progress.watchedseconds > 0) {
        label = strings.recording_progress_partial;
        percent = Math.min(99, Math.max(1, Math.floor((progress.watchedseconds / progress.thresholdseconds) * 100)));
    }

    return {label: label, percent: percent};
};

/**
 * Update the hub progress UI after a web service response.
 *
 * @returns {void}
 */
const refreshProgressUi = () => {
    const state = currentProgressState();
    const badges = findAll('.googlemeet-hub-progress .googlemeet-progress-badge');
    const meters = findAll('.googlemeet-hub-progress .googlemeet-progress-meter');
    const buttons = findAll('.googlemeet-mark-viewed');
    const aria = (strings.recording_progress_aria || '{$a}').replace('{$a}', state.label);

    let badgeclass = 'googlemeet-progress-badge-unseen';
    if (isViewed()) {
        badgeclass = 'googlemeet-progress-badge-completed';
    } else if (progress.watchedseconds > 0) {
        badgeclass = 'googlemeet-progress-badge-partial';
    }

    badges.forEach(badge => {
        badge.classList.remove(
            'googlemeet-progress-badge-completed',
            'googlemeet-progress-badge-partial',
            'googlemeet-progress-badge-unseen'
        );
        badge.classList.add(badgeclass);
        badge.setAttribute('aria-label', aria);
        setText(findAll('[aria-hidden="true"]', badge), isViewed() ? '\u2713' : '\u25cb');
        setText(findAll('.googlemeet-progress-status-text', badge), state.label);
    });
    meters.forEach(meter => {
        meter.setAttribute('aria-valuenow', state.percent);
        findAll('.progress-bar', meter).forEach(bar => {
            bar.style.width = state.percent + '%';
        });
    });

    buttons.forEach(button => {
        if (isViewed()) {
            button.disabled = true;
            button.textContent = strings.recording_progress_completed;
            button.classList.remove('btn-primary');
            button.classList.add('btn-outline-success');
        } else {
            button.disabled = false;
            button.textContent = strings.recording_mark_viewed;
            button.classList.remove('btn-outline-success');
            button.classList.add('btn-primary');
        }
    });
};

/**
 * Add visible elapsed seconds to the pending heartbeat buffer.
 *
 * @returns {void}
 */
const collectVisibleSeconds = () => {
    if (progress.completed || !progress.visibleSince) {
        return;
    }

    const now = Date.now();
    const elapsed = Math.floor((now - progress.visibleSince) / 1000);
    if (elapsed <= 0) {
        return;
    }

    progress.pendingseconds += elapsed;
    progress.visibleSince += elapsed * 1000;
};

/**
 * Persist one progress heartbeat.
 *
 * @param {number} delta Seconds to add.
 * @param {boolean} completed Whether the student marks the recording as viewed (the web service's
 *     completed parameter; it never counts towards activity completion).
 * @param {boolean} silent Whether errors should stay silent.
 * @returns {Promise}
 */
const sendProgress = (delta, completed, silent) => {
    delta = Math.max(0, Math.min(60, parseInt(delta, 10) || 0));
    if (!delta && !completed) {
        return Promise.resolve();
    }

    return Ajax.call([{
        methodname: 'mod_googlemeet_mark_recording_progress',
        args: {
            recordingid: settings.recordingid,
            coursemoduleid: settings.cmid,
            watchedsecondsdelta: delta,
            completed: !!completed,
        },
    }])[0].then(response => {
        progress.watchedseconds = parseInt(response.watchedseconds, 10) || 0;
        progress.completed = !!response.completed;
        progress.markedviewed = !!response.markedviewed;
        refreshProgressUi();
        return;
    }).catch(error => {
        progress.pendingseconds += delta;
        if (!silent) {
            refreshProgressUi();
            Notification.exception(error);
        }
        // Stay rejected so the caller's next step is skipped.
        throw error;
    });
};

/**
 * Flush pending heartbeat seconds when enough visible time has accumulated.
 *
 * @param {boolean} force Send even when below the normal interval.
 * @returns {void}
 */
const flushProgressHeartbeat = force => {
    if (progress.completed) {
        return;
    }

    collectVisibleSeconds();
    if (!force && progress.pendingseconds < 30) {
        return;
    }

    const delta = Math.min(progress.pendingseconds, 60);
    progress.pendingseconds -= delta;
    sendProgress(delta, false, true).catch(() => {
        // The seconds went back to the buffer in sendProgress(); the next beat retries them.
        return;
    });
};

/**
 * Bind recording progress heartbeat and manual completion.
 *
 * @returns {void}
 */
const bindRecordingProgress = () => {
    if (!findOne('.googlemeet-hub-progress')) {
        return;
    }

    progress.completed = !!settings.progressmeasuredcompleted;
    // The page only says whether the recording shows as viewed; without measured completion that
    // can only come from a manual mark.
    progress.markedviewed = !!settings.progresscompleted;
    progress.watchedseconds = Math.max(0, parseInt(settings.progresswatchedseconds, 10) || 0);
    progress.thresholdseconds = Math.max(30, parseInt(settings.progressthresholdseconds, 10) || 480);
    progress.pendingseconds = 0;
    progress.visibleSince = (!progress.completed && pageIsVisible()) ? Date.now() : 0;
    refreshProgressUi();

    findAll('.googlemeet-mark-viewed').forEach(button => {
        button.addEventListener('click', () => {
            if (isViewed()) {
                return;
            }

            collectVisibleSeconds();
            const delta = Math.min(progress.pendingseconds, 60);
            progress.pendingseconds -= delta;
            button.disabled = true;
            button.textContent = strings.recording_progress_saving;
            sendProgress(delta, true, false).then(() => {
                refreshProgressUi();
                return;
            }).catch(() => {
                // The failure was already reported by sendProgress().
                return;
            });
        });
    });

    document.addEventListener('visibilitychange', () => {
        if (progress.completed) {
            return;
        }

        if (pageIsVisible()) {
            progress.visibleSince = Date.now();
        } else {
            flushProgressHeartbeat(true);
            progress.visibleSince = 0;
        }
    });

    progress.timer = window.setInterval(() => {
        if (!pageIsVisible()) {
            return;
        }
        flushProgressHeartbeat(false);
    }, HEARTBEAT_INTERVAL_MS);
};

/**
 * Show the transcript tab for teacher chapter navigation.
 *
 * @returns {void}
 */
const showTranscriptTab = () => {
    const tab = document.getElementById('googlemeet-transcript-tab');
    if (!tab) {
        return;
    }
    showTab(tab);
};

/**
 * Highlight a timestamp in the teacher-only transcript panel.
 *
 * @param {string} timestamp Timestamp text.
 * @returns {boolean} Whether a matching timestamp was found.
 */
const highlightTranscriptTimestamp = timestamp => {
    const root = findOne('.googlemeet-ai-transcript-text');
    if (!root || !timestamp) {
        return false;
    }

    findAll('.googlemeet-transcript-highlight').forEach(element => {
        element.classList.remove('googlemeet-transcript-highlight');
    });
    const existing = findAll('[data-chapter-timestamp]', root)
        .find(element => element.getAttribute('data-chapter-timestamp') === timestamp);
    if (existing) {
        existing.classList.add('googlemeet-transcript-highlight');
        existing.scrollIntoView({block: 'center', behavior: 'smooth'});
        return true;
    }

    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    let node = walker.nextNode();
    while (node) {
        const index = node.nodeValue.indexOf(timestamp);
        if (index !== -1) {
            const parent = node.parentNode;
            const before = node.nodeValue.slice(0, index);
            const match = node.nodeValue.slice(index, index + timestamp.length);
            const after = node.nodeValue.slice(index + timestamp.length);
            const highlight = document.createElement('span');
            highlight.className = 'googlemeet-transcript-highlight';
            highlight.setAttribute('data-chapter-timestamp', timestamp);
            highlight.textContent = match;

            if (before !== '') {
                parent.insertBefore(document.createTextNode(before), node);
            }
            parent.insertBefore(highlight, node);
            node.nodeValue = after;
            highlight.scrollIntoView({block: 'center', behavior: 'smooth'});
            return true;
        }
        node = walker.nextNode();
    }

    return false;
};

/**
 * Copy a timestamp to the clipboard when possible.
 *
 * @param {string} timestamp Timestamp text.
 * @returns {Promise<void>}
 */
const copyTimestamp = timestamp => {
    const feedback = findAll('[data-region="chapter-feedback"]');
    const reference = (strings.chapter_timestamp_reference || '{$a}').replace('{$a}', timestamp);
    if (!navigator.clipboard || !navigator.clipboard.writeText) {
        setText(feedback, reference);
        return Promise.resolve();
    }

    return navigator.clipboard.writeText(timestamp).then(() => {
        setText(feedback, (strings.chapter_timestamp_copied || '{$a}').replace('{$a}', timestamp));
        return;
    }).catch(() => {
        setText(feedback, reference);
    });
};

/**
 * Whether the user asked the browser to reduce motion.
 *
 * @returns {boolean}
 */
const prefersReducedMotion = () => !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

/**
 * Format seconds as m:ss or h:mm:ss.
 *
 * @param {number} total Seconds.
 * @returns {string}
 */
const formatSeconds = total => {
    const value = Math.max(0, Math.floor(total || 0));
    const hours = Math.floor(value / 3600);
    const minutes = Math.floor((value % 3600) / 60);
    const seconds = value % 60;
    const pad = number => String(number).padStart(2, '0');
    return hours > 0 ? hours + ':' + pad(minutes) + ':' + pad(seconds) : minutes + ':' + pad(seconds);
};

/**
 * Get the embedded Drive preview iframe, if any.
 *
 * @returns {HTMLIFrameElement|null}
 */
const getPlayerIframe = () => document.querySelector('#googlemeet-recording-hub [data-region="recording-player-iframe"]');

/**
 * Build a Drive /preview URL that starts at the given second.
 *
 * Drive's preview player honours `?t=<seconds>`; any existing query or hash is stripped first.
 *
 * @param {string} base Embed URL.
 * @param {number} seconds Start offset.
 * @returns {string}
 */
const buildSeekUrl = (base, seconds) => {
    const clean = String(base || '').split(/[?#]/)[0];
    return seconds > 0 ? clean + '?t=' + seconds : clean;
};

/**
 * Keep a chapter visible inside the scrollable chapters panel without scrolling the page.
 *
 * @param {HTMLElement} button Chapter button.
 * @returns {void}
 */
const scrollChapterIntoPanel = button => {
    const panel = button.closest('.googlemeet-chapters-panel');
    if (!panel || panel.scrollHeight <= panel.clientHeight) {
        return;
    }
    const panelRect = panel.getBoundingClientRect();
    const rect = button.getBoundingClientRect();
    if (rect.top < panelRect.top || rect.bottom > panelRect.bottom) {
        panel.scrollTop += rect.top - panelRect.top - (panel.clientHeight / 3);
    }
};

/**
 * Bind the mobile collapse toggle of the chapters panel.
 *
 * On small screens (below 576px) the list starts collapsed under the player and the toggle
 * shows/hides it (aria-expanded + the hidden attribute on the list). On larger screens the
 * toggle is hidden by CSS and the list is always expanded.
 *
 * @returns {void}
 */
const bindChaptersToggle = () => {
    const panel = document.querySelector('#googlemeet-recording-hub .googlemeet-chapters-panel');
    const toggle = panel ? panel.querySelector('.googlemeet-chapters-toggle') : null;
    const body = panel ? panel.querySelector('#googlemeet-chapters-body') : null;
    if (!toggle || !body) {
        return;
    }
    const setExpanded = expanded => {
        panel.classList.toggle('googlemeet-chapters-collapsed', !expanded);
        body.hidden = !expanded;
        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        toggle.textContent = toggle.getAttribute(expanded ? 'data-label-hide' : 'data-label-show');
    };
    const small = window.matchMedia ? window.matchMedia('(max-width: 575.98px)') : null;
    if (small && small.matches) {
        setExpanded(false);
    }
    if (small) {
        const onChange = event => {
            // Leaving the phone layout: the toggle disappears, so never leave the list hidden.
            // Entering it (rotation, resized window): start collapsed, as on a phone page load.
            setExpanded(!event.matches);
        };
        if (small.addEventListener) {
            small.addEventListener('change', onChange);
        } else if (small.addListener) {
            small.addListener(onChange);
        }
    }
    toggle.addEventListener('click', () => {
        setExpanded(toggle.getAttribute('aria-expanded') !== 'true');
    });
};

/**
 * Mark the chapter that contains the given second as the current one.
 *
 * @param {number} seconds Playback offset.
 * @returns {void}
 */
const markActiveChapter = seconds => {
    let active = null;
    findAll('.googlemeet-chapter-button').forEach(button => {
        const start = parseInt(button.getAttribute('data-start-seconds'), 10);
        if (!Number.isNaN(start) && start <= seconds) {
            active = button;
        }
        button.removeAttribute('aria-current');
        button.classList.remove('googlemeet-chapter-active');
    });
    if (active) {
        active.setAttribute('aria-current', 'true');
        active.classList.add('googlemeet-chapter-active');
        scrollChapterIntoPanel(active);
    }
};

/**
 * Remember the last point the user jumped to (approximate resume, not playback position).
 *
 * @param {number} seconds Offset in seconds.
 * @returns {void}
 */
const rememberLastJump = seconds => {
    if (!settings.recordingid || settings.lastjump === seconds) {
        return;
    }
    settings.lastjump = seconds;
    Ajax.call([{
        methodname: 'core_user_set_user_preferences',
        args: {preferences: [{name: 'mod_googlemeet_lastjump_' + settings.recordingid, value: String(seconds)}]},
    }])[0].catch(() => {
        // Guests or restricted sessions cannot store preferences; resuming is best effort.
        return;
    });
};

/**
 * Seek the recording player to a second.
 *
 * A native <video> is seeked in place; the Drive /preview iframe is reloaded with `?t=<seconds>`.
 *
 * @param {number} rawseconds Offset in seconds.
 * @param {object} [options] Options.
 * @param {boolean} [options.scroll=true] Scroll the player into view.
 * @param {boolean} [options.announce=true] Announce the jump to assistive technology.
 * @param {boolean} [options.remember=true] Store it as the user's last jump point for this recording.
 * @returns {boolean} Whether a player was found and seeked.
 */
const seekTo = (rawseconds, {scroll = true, announce = true, remember = true} = {}) => {
    const seconds = Math.max(0, Math.floor(Number(rawseconds) || 0));
    const nativeVideo = document.querySelector('#googlemeet-recording-hub video');
    const iframe = nativeVideo ? null : getPlayerIframe();
    if (!nativeVideo && !iframe) {
        return false;
    }

    if (nativeVideo) {
        nativeVideo.currentTime = seconds;
    } else {
        iframe.src = buildSeekUrl(iframe.getAttribute('data-embed-base') || iframe.src, seconds);
    }

    markActiveChapter(seconds);
    if (remember) {
        rememberLastJump(seconds);
    }

    if (scroll) {
        const player = (nativeVideo || iframe).closest('.googlemeet-recording-player') || nativeVideo || iframe;
        const rect = player.getBoundingClientRect();
        if (rect.top < 0 || rect.bottom > (window.innerHeight || document.documentElement.clientHeight)) {
            player.scrollIntoView({block: 'start', behavior: prefersReducedMotion() ? 'auto' : 'smooth'});
        }
    }

    if (announce) {
        setText(
            findAll('[data-region="seek-feedback"]'),
            (strings.chapter_seek_announce || '{$a}').replace('{$a}', formatSeconds(seconds))
        );
    }

    return true;
};

/**
 * Build the hub deep link that opens the player at a given second.
 *
 * @param {number} seconds Offset in seconds.
 * @returns {string}
 */
const buildMomentLink = seconds => {
    const hub = document.getElementById('googlemeet-recording-hub');
    const base = hub ? hub.getAttribute('data-hub-url') : '';
    if (!base) {
        return '';
    }
    const url = new URL(base, window.location.href);
    if (seconds > 0) {
        url.searchParams.set('t', String(seconds));
    } else {
        url.searchParams.delete('t');
    }
    return url.toString();
};

/**
 * Copy a deep link to a chapter moment.
 *
 * @param {number} seconds Offset in seconds.
 * @param {string} timestamp Timestamp label.
 * @returns {Promise<void>}
 */
const copyMomentLink = (seconds, timestamp) => {
    const feedback = findAll('[data-region="chapter-feedback"]');
    const link = buildMomentLink(seconds);
    const manual = () => setText(feedback, (strings.chapter_link_copy_manual || '{$a}').replace('{$a}', link));
    if (!link) {
        return Promise.resolve();
    }
    if (!navigator.clipboard || !navigator.clipboard.writeText) {
        manual();
        return Promise.resolve();
    }
    return navigator.clipboard.writeText(link).then(() => {
        setText(feedback, (strings.chapter_link_copied || '{$a}').replace('{$a}', timestamp));
        return;
    }).catch(manual);
};

/**
 * Reflect a deep-link start time (?t=) rendered by the server in the chapter list.
 *
 * @returns {void}
 */
const applyInitialStart = () => {
    const hub = document.getElementById('googlemeet-recording-hub');
    const start = hub ? parseInt(hub.getAttribute('data-start-seconds'), 10) : 0;
    if (start > 0 && getPlayerIframe()) {
        markActiveChapter(start);
    }
};

/**
 * Bind timestamped chapter interactions.
 *
 * @returns {void}
 */
const bindChapters = () => {
    findAll('.googlemeet-chapter-button').forEach(button => button.addEventListener('click', () => {
        const timestamp = button.getAttribute('data-chapter-timestamp') || '';
        const seconds = parseInt(button.getAttribute('data-start-seconds'), 10);
        const feedback = findAll('[data-region="chapter-feedback"]');

        if (!Number.isNaN(seconds) && seconds >= 0 && seekTo(seconds)) {
            return;
        }

        if (settings.caneditrecording && document.getElementById('googlemeet-transcript-tab')) {
            showTranscriptTab();
            window.setTimeout(() => {
                if (highlightTranscriptTimestamp(timestamp)) {
                    setText(feedback, (strings.chapter_transcript_highlighted || '{$a}').replace('{$a}', timestamp));
                    return;
                }
                copyTimestamp(timestamp);
            }, 160);
            return;
        }

        copyTimestamp(timestamp);
    }));

    findAll('.googlemeet-chapter-copylink').forEach(button => button.addEventListener('click', () => {
        const seconds = Math.max(0, parseInt(button.getAttribute('data-start-seconds'), 10) || 0);
        copyMomentLink(seconds, button.getAttribute('data-chapter-timestamp') || '');
    }));

    applyInitialStart();

    findAll('.googlemeet-resume-button').forEach(button => button.addEventListener('click', () => {
        seekTo(parseInt(button.getAttribute('data-seek-seconds'), 10));
    }));

    bindChaptersToggle();
};

/**
 * Containers whose AI-generated text may contain mm:ss / h:mm:ss timestamps.
 *
 * @type {string}
 */
const TIMESTAMP_CONTAINERS = [
    '.googlemeet-ai-summary',
    '.googlemeet-ai-keypoints',
    '.googlemeet-ai-topics',
    '.googlemeet-ai-transcript-text',
].join(',');

/**
 * Matches m:ss, mm:ss, h:mm:ss and hh:mm:ss not glued to other digits or colons.
 *
 * @type {RegExp}
 */
const TIMESTAMP_PATTERN = /(^|[^\d:])((?:\d{1,2}:)?[0-5]?\d:[0-5]\d)(?![\d:])/g;

/**
 * Convert a timestamp to seconds.
 *
 * @param {string} timestamp m:ss or h:mm:ss.
 * @returns {number} Seconds, or -1 when invalid.
 */
const timestampToSeconds = timestamp => {
    const parts = String(timestamp || '').split(':').map(part => parseInt(part, 10));
    if (parts.length < 2 || parts.length > 3 || parts.some(part => Number.isNaN(part))) {
        return -1;
    }
    const seconds = parts.pop();
    const minutes = parts.pop();
    const hours = parts.length ? parts.pop() : 0;
    if (seconds > 59 || minutes > 59) {
        return -1;
    }
    return hours * 3600 + minutes * 60 + seconds;
};

/**
 * Build a keyboard-accessible seek button for a timestamp.
 *
 * @param {string} timestamp Timestamp text.
 * @param {number} seconds Offset in seconds.
 * @returns {HTMLButtonElement}
 */
const createTimestampLink = (timestamp, seconds) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'googlemeet-timestamp-link';
    button.setAttribute('data-seek-seconds', String(seconds));
    button.setAttribute('data-chapter-timestamp', timestamp);
    button.setAttribute('aria-label', (strings.timestamp_seek_aria || '{$a}').replace('{$a}', timestamp));
    button.textContent = timestamp;
    return button;
};

/**
 * Turn plain-text timestamps inside an element into seek buttons.
 *
 * @param {Element} root Container.
 * @param {number} maxseconds Recording duration in seconds (0 when unknown).
 * @returns {void}
 */
const linkifyTimestampsIn = (root, maxseconds) => {
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
        acceptNode: node => {
            if (!node.nodeValue || node.nodeValue.indexOf(':') === -1) {
                return NodeFilter.FILTER_REJECT;
            }
            const parent = node.parentElement;
            if (parent && parent.closest('a, button, input, textarea, select, .googlemeet-timestamp-link')) {
                return NodeFilter.FILTER_REJECT;
            }
            return NodeFilter.FILTER_ACCEPT;
        },
    });
    const nodes = [];
    let current = walker.nextNode();
    while (current) {
        nodes.push(current);
        current = walker.nextNode();
    }

    nodes.forEach(node => {
        const text = node.nodeValue;
        const fragment = document.createDocumentFragment();
        let last = 0;
        let changed = false;
        TIMESTAMP_PATTERN.lastIndex = 0;
        let match = TIMESTAMP_PATTERN.exec(text);
        while (match) {
            const timestamp = match[2];
            const start = match.index + match[1].length;
            const seconds = timestampToSeconds(timestamp);
            if (seconds >= 0 && (!maxseconds || seconds <= maxseconds)) {
                fragment.appendChild(document.createTextNode(text.slice(last, start)));
                fragment.appendChild(createTimestampLink(timestamp, seconds));
                last = start + timestamp.length;
                changed = true;
            }
            match = TIMESTAMP_PATTERN.exec(text);
        }
        if (!changed) {
            return;
        }
        fragment.appendChild(document.createTextNode(text.slice(last)));
        node.parentNode.replaceChild(fragment, node);
    });
};

/**
 * Make every timestamp rendered in the hub AI output seek the player.
 *
 * Only runs when there is a player to seek; otherwise timestamps stay plain text.
 *
 * @returns {void}
 */
const bindTimestampLinks = () => {
    const hub = document.getElementById('googlemeet-recording-hub');
    if (!hub || (!getPlayerIframe() && !hub.querySelector('video'))) {
        return;
    }
    const maxseconds = parseInt(hub.getAttribute('data-duration-seconds'), 10) || 0;
    hub.querySelectorAll(TIMESTAMP_CONTAINERS).forEach(root => linkifyTimestampsIn(root, maxseconds));

    hub.addEventListener('click', e => {
        const link = e.target.closest('.googlemeet-timestamp-link');
        if (!link || !hub.contains(link)) {
            return;
        }
        e.preventDefault();
        seekTo(parseInt(link.getAttribute('data-seek-seconds'), 10));
    });
};

/**
 * Fold text for accent/case-insensitive search, keeping a map back to the original offsets.
 *
 * @param {string} text Original text.
 * @returns {{folded: string, map: number[]}}
 */
const foldText = text => {
    let folded = '';
    const map = [];
    for (let index = 0; index < text.length; index++) {
        const chunk = text[index].normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
        for (let offset = 0; offset < chunk.length; offset++) {
            folded += chunk[offset];
            map.push(index);
        }
    }
    return {folded, map};
};

/**
 * Remove previous transcript search highlights.
 *
 * @param {Element} root Transcript container.
 * @returns {void}
 */
const clearTranscriptMatches = root => {
    root.querySelectorAll('mark.googlemeet-transcript-match').forEach(mark => {
        mark.replaceWith(document.createTextNode(mark.textContent));
    });
    root.normalize();
};

/**
 * Find the transcript timestamp that precedes a node.
 *
 * @param {Element} root Transcript container.
 * @param {Node} node Node inside the transcript.
 * @returns {HTMLElement|null}
 */
const precedingTimestamp = (root, node) => {
    let found = null;
    root.querySelectorAll('.googlemeet-timestamp-link').forEach(link => {
        // eslint-disable-next-line no-bitwise
        if (link.compareDocumentPosition(node) & Node.DOCUMENT_POSITION_FOLLOWING) {
            found = link;
        }
    });
    return found;
};

/**
 * Highlight every match of a query inside the transcript.
 *
 * @param {Element} root Transcript container.
 * @param {string} query Search text.
 * @returns {HTMLElement[]} Marks in document order.
 */
const highlightTranscriptMatches = (root, query) => {
    clearTranscriptMatches(root);
    const needle = foldText(query.trim()).folded;
    if (needle.length < 2) {
        return [];
    }

    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
        acceptNode: node => (node.parentElement && node.parentElement.closest('.googlemeet-timestamp-link'))
            ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT,
    });
    const nodes = [];
    let current = walker.nextNode();
    while (current) {
        nodes.push(current);
        current = walker.nextNode();
    }

    const marks = [];
    nodes.forEach(node => {
        const text = node.nodeValue;
        const {folded, map} = foldText(text);
        let from = folded.indexOf(needle);
        if (from === -1) {
            return;
        }
        const fragment = document.createDocumentFragment();
        let last = 0;
        while (from !== -1) {
            const start = map[from];
            const end = map[from + needle.length - 1] + 1;
            if (start >= last) {
                fragment.appendChild(document.createTextNode(text.slice(last, start)));
                const mark = document.createElement('mark');
                mark.className = 'googlemeet-transcript-match';
                mark.textContent = text.slice(start, end);
                fragment.appendChild(mark);
                marks.push(mark);
                last = end;
            }
            from = folded.indexOf(needle, from + needle.length);
        }
        fragment.appendChild(document.createTextNode(text.slice(last)));
        node.parentNode.replaceChild(fragment, node);
    });

    marks.forEach(mark => {
        const stamp = precedingTimestamp(root, mark);
        if (!stamp) {
            return;
        }
        mark.setAttribute('role', 'button');
        mark.setAttribute('tabindex', '0');
        mark.setAttribute('data-seek-seconds', stamp.getAttribute('data-seek-seconds'));
        mark.setAttribute('aria-label', mark.textContent + ' - ' +
            (strings.transcript_search_match_aria || '{$a}').replace('{$a}', stamp.textContent));
    });

    return marks;
};

/**
 * Bind the teacher transcript search: highlight matches, Enter cycles, clicking a match seeks.
 *
 * @returns {void}
 */
const bindTranscriptSearch = () => {
    const input = document.querySelector('#googlemeet-recording-hub .googlemeet-transcript-search-input');
    const root = document.querySelector('#googlemeet-recording-hub .googlemeet-ai-transcript-text');
    if (!input || !root) {
        return;
    }
    const status = document.getElementById('googlemeet-transcript-search-status');
    let marks = [];
    let cursor = -1;
    let timer = null;

    const run = () => {
        marks = highlightTranscriptMatches(root, input.value);
        cursor = -1;
        if (!status) {
            return;
        }
        if (input.value.trim().length < 2) {
            status.textContent = '';
        } else if (marks.length) {
            status.textContent = (strings.transcript_search_count || '{$a}').replace('{$a}', marks.length);
        } else {
            status.textContent = strings.transcript_search_none || '';
        }
    };

    input.addEventListener('input', () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(run, 200);
    });
    input.addEventListener('keydown', e => {
        if (e.key !== 'Enter') {
            return;
        }
        e.preventDefault();
        window.clearTimeout(timer);
        if (cursor === -1 && !marks.length) {
            run();
        }
        if (!marks.length) {
            return;
        }
        if (marks[cursor]) {
            marks[cursor].classList.remove('googlemeet-transcript-match-current');
        }
        cursor = (cursor + 1) % marks.length;
        marks[cursor].classList.add('googlemeet-transcript-match-current');
        marks[cursor].scrollIntoView({block: 'center', behavior: prefersReducedMotion() ? 'auto' : 'smooth'});
    });

    const activate = mark => {
        const seconds = parseInt(mark.getAttribute('data-seek-seconds'), 10);
        if (!Number.isNaN(seconds)) {
            seekTo(seconds);
        }
    };
    const matchAt = e => {
        const mark = e.target.closest('mark.googlemeet-transcript-match[data-seek-seconds]');
        return (mark && root.contains(mark)) ? mark : null;
    };
    root.addEventListener('click', e => {
        const mark = matchAt(e);
        if (mark) {
            activate(mark);
        }
    });
    root.addEventListener('keydown', e => {
        const mark = matchAt(e);
        if (mark && (e.key === 'Enter' || e.key === ' ')) {
            e.preventDefault();
            activate(mark);
        }
    });
};

/**
 * The question id of the card an action button sits in (NaN outside a card).
 *
 * @param {HTMLElement} button Action button.
 * @returns {number}
 */
const cardQuestionId = button => {
    const card = button.closest('.googlemeet-question-card');
    return parseInt(card ? card.getAttribute('data-questionid') : '', 10);
};

/**
 * Bind teacher question-management actions.
 *
 * @returns {void}
 */
const bindQuestionManagement = () => {
    findAll('.googlemeet-question-generate').forEach(button => button.addEventListener('click', () => {
        button.disabled = true;
        button.textContent = strings.question_generating;
        call('mod_googlemeet_queue_generate_questions', {
            recordingid: settings.recordingid,
            coursemoduleid: settings.cmid,
            count: parseInt(button.getAttribute('data-count'), 10) || 10,
        });
    }));

    findAll('.googlemeet-question-unpublish').forEach(button => button.addEventListener('click', () => {
        const questionid = cardQuestionId(button);
        confirmAction(
            strings.question_unpublish,
            strings.question_unpublish_confirm,
            strings.question_unpublish,
            button
        ).then(() => call('mod_googlemeet_unpublish_questions', {
            recordingid: settings.recordingid,
            coursemoduleid: settings.cmid,
            questionids: [questionid],
        })).catch(() => {
            // Cancelled, or the request failed and call() already reported it.
            return;
        });
    }));

    findAll('.googlemeet-question-discard').forEach(button => button.addEventListener('click', () => {
        const questionid = cardQuestionId(button);
        confirmAction(
            strings.question_discard,
            strings.question_discard_confirm,
            strings.question_discard,
            button
        ).then(() => call('mod_googlemeet_discard_questions', {
            recordingid: settings.recordingid,
            coursemoduleid: settings.cmid,
            questionids: [questionid],
        })).catch(() => {
            // Cancelled, or the request failed and call() already reported it.
            return;
        });
    }));

    // Selection, publish and bulk actions live in mod_googlemeet/question_review.

    findAll('.googlemeet-question-edit').forEach(button => button.addEventListener('click', () => {
        const card = button.closest('.googlemeet-question-card');
        setValue('googlemeet-question-edit-id', card ? card.getAttribute('data-questionid') : undefined);
        setValue('googlemeet-question-edit-stem', button.getAttribute('data-stem') || '');
        setValue('googlemeet-question-edit-feedback', button.getAttribute('data-feedback') || '');
        setValue('googlemeet-question-edit-correct', button.getAttribute('data-correctindex') || '0');
        findAll('.googlemeet-question-edit-answer').forEach(field => {
            field.value = button.getAttribute('data-answer' + field.getAttribute('data-index')) || '';
        });
        const modal = document.getElementById('googlemeet-question-edit-modal');
        if (modal) {
            BootstrapModal.getOrCreateInstance(modal).show();
        }
    }));

    const save = document.getElementById('googlemeet-question-edit-save');
    if (save) {
        save.addEventListener('click', () => {
            const options = findAll('.googlemeet-question-edit-answer').map(field => field.value);
            call('mod_googlemeet_update_question', {
                recordingid: settings.recordingid,
                coursemoduleid: settings.cmid,
                questionid: parseInt(valueOf('googlemeet-question-edit-id'), 10),
                stem: valueOf('googlemeet-question-edit-stem'),
                options: options,
                correctindex: parseInt(valueOf('googlemeet-question-edit-correct'), 10),
                explanation: valueOf('googlemeet-question-edit-feedback'),
                citation: '',
            });
        });
    }
};

/**
 * Bind teacher recording actions.
 *
 * @returns {void}
 */
const bindRecordingManagement = () => {
    findAll('.recordinghowhide').forEach(button => button.addEventListener('click', () => {
        Ajax.call([{
            methodname: 'mod_googlemeet_showhide_recording',
            args: {
                recordingid: settings.recordingid,
                coursemoduleid: settings.cmid,
            },
        }])[0].then(response => {
            const visible = response.visible === true || response.visible === 1 ||
                response.visible === '1' || response.visible === 'true';
            updateVisibilityButton(button, visible);
            return;
        }).catch(Notification.exception);
    }));

    findAll('.recordingeditname').forEach(trigger => trigger.addEventListener('click', () => {
        const title = findOne('.recording-name-text');
        const current = (title && title.getAttribute('data-originalname')) || (title ? title.textContent.trim() : '');

        // The modal root, kept for the step that runs once the body is rendered.
        let modalroot = null;
        Modal.create({
            title: strings.recording_rename,
            body: '<div class="mb-3">' +
                '<label class="form-label" for="googlemeet-recording-rename-input">' +
                escapeHtml(strings.name) + '</label>' +
                '<input type="text" class="form-control" id="googlemeet-recording-rename-input">' +
                '</div>',
            footer: '<button type="button" class="btn btn-secondary" data-action="hide">' +
                escapeHtml(strings.cancel) + '</button>' +
                '<button type="button" class="btn btn-primary googlemeet-recording-rename-save">' +
                escapeHtml(strings.savechanges) + '</button>',
            removeOnClose: true,
            show: true,
            returnElement: trigger,
        }).then(modal => {
            // The core modal hands back a jQuery-wrapped root; work on its element.
            const root = modal.getRoot()[0];
            modalroot = root;
            const input = () => root.querySelector('#googlemeet-recording-rename-input');
            root.addEventListener('click', event => {
                const saveButton = event.target.closest('.googlemeet-recording-rename-save');
                if (!saveButton || !root.contains(saveButton)) {
                    return;
                }
                event.preventDefault();
                const field = input();
                const name = field ? field.value : undefined;
                if (!name) {
                    if (field) {
                        field.focus();
                    }
                    return;
                }
                saveButton.disabled = true;
                modal.destroy();
                call('mod_googlemeet_recording_edit_name', {
                    recordingid: settings.recordingid,
                    name: name,
                    coursemoduleid: settings.cmid,
                });
            });
            root.addEventListener('keydown', event => {
                if (!event.target.closest('#googlemeet-recording-rename-input')) {
                    return;
                }
                if ((event.which || event.keyCode) === 13) {
                    event.preventDefault();
                    const saveButton = root.querySelector('.googlemeet-recording-rename-save');
                    if (saveButton) {
                        saveButton.click();
                    }
                }
            });
            return modal.getBodyPromise();
        }).then(() => {
            const field = modalroot.querySelector('#googlemeet-recording-rename-input');
            if (field) {
                field.value = current;
                field.focus();
                field.select();
            }
            return;
        }).catch(Notification.exception);
    }));
};

/**
 * Find the correct option in the current practice question.
 *
 * @param {Object} question Practice question.
 * @param {number|string} answerid Answer ID.
 * @returns {Object|null}
 */
const findCorrectOption = (question, answerid) => {
    for (let i = 0; i < question.options.length; i++) {
        if (parseInt(question.options[i].answerid, 10) === parseInt(answerid, 10)) {
            return question.options[i];
        }
    }
    return null;
};

/**
 * The value of an attribute on the first element of a list (undefined for an empty list).
 *
 * @param {HTMLElement[]} elements Elements.
 * @param {string} name Attribute name.
 * @returns {string|null|undefined}
 */
const firstAttr = (elements, name) => (elements[0] ? elements[0].getAttribute(name) : undefined);

/**
 * Update the practice header (position, live score and progress bar).
 *
 * @param {Object} practice Practice state.
 * @param {HTMLElement} player Practice player.
 * @param {boolean} answered Whether the current question has been answered.
 * @returns {void}
 */
const updatePracticeHeader = (practice, player, answered) => {
    const total = practice.queue.length;
    const current = Math.min(practice.index + 1, total);
    const done = practice.index + (answered ? 1 : 0);
    const label = (player.getAttribute('data-progress-tpl') || '{$a->current} / {$a->total}')
        .replace('{$a->current}', current)
        .replace('{$a->total}', total);
    let roundLabel = '';
    if (practice.reviewing) {
        roundLabel = strings.practice_round_review + ' · ';
    }
    setText(findAll('.googlemeet-practice-progress'), roundLabel + label);
    const live = findAll('.googlemeet-practice-livescore');
    const liveTemplate = firstAttr(live, 'data-template') || '__a__';
    live.forEach(element => {
        element.classList.remove('d-none');
        element.textContent = liveTemplate.replace('__a__', practice.correct);
    });
    const pct = total ? Math.round((done / total) * 100) : 0;
    findAll('.googlemeet-practice-meter').forEach(meter => {
        meter.classList.remove('d-none');
        meter.setAttribute('aria-valuenow', pct);
        findAll('.progress-bar', meter).forEach(bar => {
            bar.style.width = pct + '%';
        });
    });
};

/**
 * Give the practice stem a focus target and move the focus there.
 *
 * @returns {void}
 */
const focusPracticeStem = () => {
    findAll('.googlemeet-practice-stem').forEach(stem => {
        stem.setAttribute('tabindex', '-1');
        stem.focus();
    });
};

/**
 * Render the current practice question.
 *
 * @param {Object} practice Practice state.
 * @param {HTMLElement} player Practice player.
 * @returns {void}
 */
const renderPracticeQuestion = (practice, player) => {
    const question = practice.queue[practice.index];
    const letters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
    let optionsHtml = '';

    practice.checked = false;
    findAll('.googlemeet-practice-loading, .googlemeet-practice-complete, .googlemeet-practice-error').forEach(element => {
        element.classList.add('d-none');
    });
    findAll('.googlemeet-practice-question').forEach(element => {
        element.classList.remove('d-none');
    });
    updatePracticeHeader(practice, player, false);
    // Stem, options and explanation are format_text() output from question_service: insert them
    // as HTML; escaping them here would break their formatting (bold, sub/sup).
    findAll('.googlemeet-practice-stem').forEach(stem => {
        stem.innerHTML = question.stem;
    });
    findAll('.googlemeet-practice-feedback').forEach(feedback => {
        feedback.classList.add('d-none');
        feedback.classList.remove('alert', 'alert-success', 'alert-warning');
        feedback.textContent = '';
    });
    findAll('.googlemeet-practice-check').forEach(button => {
        button.disabled = true;
        button.classList.remove('d-none');
    });
    findAll('.googlemeet-practice-next').forEach(button => {
        button.classList.add('d-none');
    });

    question.options.forEach((option, index) => {
        const inputid = 'googlemeet-practice-' + question.questionid + '-' + option.answerid;
        optionsHtml += '<label class="googlemeet-practice-option" for="' + inputid + '">' +
            '<input class="form-check-input googlemeet-practice-answer" type="radio" ' +
            'name="googlemeet-practice-answer" id="' + inputid + '" value="' + option.answerid + '">' +
            '<span class="googlemeet-practice-letter" aria-hidden="true">' + (letters[index] || index + 1) + '</span>' +
            '<span class="googlemeet-practice-option-text">' + option.text + '</span>' +
            '<span class="googlemeet-practice-option-state"></span>' +
            '</label>';
    });
    findAll('.googlemeet-practice-options').forEach(options => {
        options.innerHTML = optionsHtml;
    });
};

/**
 * Show the practice completion state with the score of the round just finished.
 *
 * @param {Object} practice Practice state.
 * @param {HTMLElement} player Practice player.
 * @returns {void}
 */
const showPracticeComplete = (practice, player) => {
    const total = practice.queue.length;
    const pct = total ? Math.round((practice.correct / total) * 100) : 0;
    const complete = findOne('.googlemeet-practice-complete');
    let level = 'low';
    if (pct >= 80) {
        level = 'great';
    } else if (pct >= 50) {
        level = 'good';
    }

    updatePracticeHeader(practice, player, true);
    findAll('.googlemeet-practice-question, .googlemeet-practice-loading, .googlemeet-practice-error').forEach(element => {
        element.classList.add('d-none');
    });
    if (!complete) {
        return;
    }
    const message = findAll('.googlemeet-practice-message', complete);
    const scoreText = findAll('.googlemeet-practice-score-text', complete);
    complete.classList.remove(
        'd-none',
        'googlemeet-practice-level-great',
        'googlemeet-practice-level-good',
        'googlemeet-practice-level-low'
    );
    complete.classList.add('googlemeet-practice-level-' + level);
    setText(findAll('.googlemeet-practice-score-value', complete), pct + '%');
    setText(scoreText, (firstAttr(scoreText, 'data-template') || '{$a->correct}/{$a->total}')
        .replace('{$a->correct}', practice.correct)
        .replace('{$a->total}', total));
    setText(message, firstAttr(message, 'data-' + level) || '');

    const review = findAll('.googlemeet-practice-review', complete);
    const reviewText = (firstAttr(review, 'data-template') || '__a__').replace('__a__', practice.wrong.length);
    review.forEach(button => {
        button.classList.toggle('d-none', practice.wrong.length === 0);
        button.textContent = reviewText;
    });
    complete.focus();
};

/**
 * Bind the student practice player.
 *
 * @returns {void}
 */
const bindPracticePlayer = () => {
    const player = findOne('.googlemeet-practice-player');
    if (!player || settings.canmanagequestions || !settings.hasquestions) {
        return;
    }

    const practice = {
        questions: [],
        queue: [],
        index: 0,
        checked: false,
        correct: 0,
        wrong: [],
        reviewing: false,
    };

    const startRound = (queue, reviewing) => {
        practice.queue = queue.slice();
        practice.index = 0;
        practice.correct = 0;
        practice.wrong = [];
        practice.reviewing = reviewing;
        renderPracticeQuestion(practice, player);
        focusPracticeStem();
    };

    const loadPracticeQuestions = () => {
        if (player.getAttribute('data-loaded') === '1') {
            return;
        }
        player.setAttribute('data-loaded', '1');
        Ajax.call([{
            methodname: 'mod_googlemeet_get_practice_questions',
            args: {
                recordingid: settings.recordingid,
                coursemoduleid: settings.cmid,
            },
        }])[0].then(response => {
            practice.questions = response.questions || [];
            if (!practice.questions.length) {
                findAll('.googlemeet-practice-loading').forEach(loading => {
                    loading.classList.remove('alert-info');
                    loading.classList.add('alert-secondary');
                    loading.textContent = strings.question_empty_student;
                });
                return;
            }
            practice.queue = practice.questions.slice();
            practice.index = 0;
            renderPracticeQuestion(practice, player);
            return;
        }).catch(error => {
            findAll('.googlemeet-practice-loading').forEach(loading => {
                loading.classList.add('d-none');
            });
            findAll('.googlemeet-practice-error').forEach(element => {
                element.classList.remove('d-none');
                element.textContent = error.message || strings.ai_error_unknown;
            });
        });
    };

    const questionsTab = document.getElementById('googlemeet-questions-tab');
    if (questionsTab) {
        // Bootstrap 5 dispatches its tab events as native DOM events.
        questionsTab.addEventListener('shown.bs.tab', loadPracticeQuestions);
    }
    const questionsPanel = document.getElementById('googlemeet-questions-panel');
    if (questionsPanel && questionsPanel.classList.contains('active')) {
        loadPracticeQuestions();
    }

    player.addEventListener('change', e => {
        const answer = e.target.closest('.googlemeet-practice-answer');
        if (!answer || !player.contains(answer)) {
            return;
        }
        findAll('.googlemeet-practice-option', player).forEach(option => {
            option.classList.remove('googlemeet-practice-option-selected');
        });
        const selected = answer.closest('.googlemeet-practice-option');
        if (selected) {
            selected.classList.add('googlemeet-practice-option-selected');
        }
        findAll('.googlemeet-practice-check').forEach(button => {
            button.disabled = false;
        });
    });

    const checkButtons = findAll('.googlemeet-practice-check');
    const setCheckDisabled = disabled => checkButtons.forEach(button => {
        button.disabled = disabled;
    });
    checkButtons.forEach(checkButton => checkButton.addEventListener('click', () => {
        if (practice.checked) {
            return;
        }
        const question = practice.queue[practice.index];
        const checked = findOne('.googlemeet-practice-answer:checked');
        const answerid = parseInt(checked ? checked.value : undefined, 10);
        if (!answerid) {
            return;
        }
        findAll('.googlemeet-practice-error').forEach(element => {
            element.classList.add('d-none');
        });
        setCheckDisabled(true);
        Ajax.call([{
            methodname: 'mod_googlemeet_check_practice_answer',
            args: {
                recordingid: settings.recordingid,
                coursemoduleid: settings.cmid,
                questionid: question.questionid,
                answerid: answerid,
            },
        }])[0].then(response => {
            practice.checked = true;
            if (response.correct) {
                practice.correct++;
            } else {
                practice.wrong.push(question);
            }
            const answers = findAll('.googlemeet-practice-answer');
            answers.forEach(answer => {
                answer.disabled = true;
            });
            findAll('.googlemeet-practice-options', player).forEach(options => {
                options.classList.add('googlemeet-practice-options-answered');
            });
            answers.forEach(answer => {
                const option = answer.closest('.googlemeet-practice-option');
                const states = option ? findAll('.googlemeet-practice-option-state', option) : [];
                if (parseInt(answer.value, 10) === parseInt(response.correctanswerid, 10)) {
                    if (option) {
                        option.classList.add('googlemeet-practice-option-correct');
                    }
                    states.forEach(state => {
                        state.innerHTML = '<span aria-hidden="true">&#10003;</span>' +
                            '<span class="googlemeet-practice-option-statetext">' +
                            escapeHtml(strings.practice_right_option) + '</span>';
                    });
                } else if (answer.checked) {
                    if (option) {
                        option.classList.add('googlemeet-practice-option-incorrect');
                    }
                    states.forEach(state => {
                        state.innerHTML = '<span aria-hidden="true">&#10007;</span>' +
                            '<span class="googlemeet-practice-option-statetext">' +
                            escapeHtml(strings.practice_your_answer) + '</span>';
                    });
                }
            });

            const correctOption = findCorrectOption(question, response.correctanswerid);
            const correctText = correctOption ? correctOption.text : '';
            const feedbackClass = response.correct ? 'alert-success' : 'alert-warning';
            const statusText = response.correct ? strings.practice_correct : strings.practice_incorrect;
            const icon = response.correct ? '&#10003;' : '&#10007;';
            const feedbackHtml = '<div class="fw-bold"><span aria-hidden="true">' + icon + '</span> ' +
                escapeHtml(statusText) + '</div>' +
                (response.correct ? '' : '<div class="mt-2"><strong>' + escapeHtml(strings.practice_correct_answer) +
                    '</strong> ' + correctText + '</div>') +
                (response.explanation ? '<div class="mt-2">' + response.explanation + '</div>' : '');
            findAll('.googlemeet-practice-feedback').forEach(feedback => {
                feedback.classList.remove('d-none', 'alert-success', 'alert-warning');
                feedback.classList.add('alert', feedbackClass);
                feedback.innerHTML = feedbackHtml;
            });
            updatePracticeHeader(practice, player, true);
            checkButtons.forEach(button => {
                button.classList.add('d-none');
            });
            const nextText = practice.index + 1 >= practice.queue.length ? strings.practice_finish : strings.practice_next;
            findAll('.googlemeet-practice-next').forEach(button => {
                button.classList.remove('d-none');
                button.textContent = nextText;
                button.focus();
            });
            return;
        }).catch(error => {
            setCheckDisabled(false);
            findAll('.googlemeet-practice-error').forEach(element => {
                element.classList.remove('d-none');
                element.textContent = error.message || strings.ai_error_unknown;
            });
        });
    }));

    findAll('.googlemeet-practice-next').forEach(button => button.addEventListener('click', () => {
        practice.index++;
        if (practice.index >= practice.queue.length) {
            showPracticeComplete(practice, player);
        } else {
            renderPracticeQuestion(practice, player);
            focusPracticeStem();
        }
    }));

    findAll('.googlemeet-practice-review').forEach(button => button.addEventListener('click', () => {
        startRound(practice.wrong, true);
    }));
    findAll('.googlemeet-practice-retry').forEach(button => button.addEventListener('click', () => {
        startRound(practice.questions, false);
    }));
};

/**
 * Deep link to a tab with #summary, #questions, #materials, #transcript or #notes,
 * keep the hash in sync when switching, and scroll the active tab into view on mobile.
 *
 * @returns {void}
 */
const bindTabHash = () => {
    const hub = document.getElementById('googlemeet-recording-hub');
    if (!hub) {
        return;
    }
    const tabs = Array.from(hub.querySelectorAll('[data-hub-tab]'));
    const byName = name => tabs.find(tab => tab.getAttribute('data-hub-tab') === name);
    const reveal = tab => {
        const strip = tab.closest('.googlemeet-hub-tabs');
        if (strip && strip.scrollWidth > strip.clientWidth) {
            strip.scrollLeft = Math.max(0, tab.offsetLeft - (strip.clientWidth - tab.offsetWidth) / 2);
        }
    };
    const fromHash = () => {
        const name = (window.location.hash || '').replace(/^#/, '');
        const tab = name ? byName(name) : null;
        if (tab && !tab.classList.contains('active')) {
            showTab(tab);
        }
        return tab;
    };

    const initial = fromHash();
    if (initial) {
        reveal(initial);
        hub.querySelector('.googlemeet-hub-tabs').scrollIntoView({block: 'start'});
    }
    window.addEventListener('hashchange', fromHash);
    // Buttons that jump to another tab (study sidebar, end of practice).
    hub.addEventListener('click', e => {
        const trigger = e.target.closest('[data-hub-goto]');
        if (!trigger || !hub.contains(trigger)) {
            return;
        }
        const tab = byName(trigger.getAttribute('data-hub-goto'));
        if (tab) {
            showTab(tab);
            reveal(tab);
            tab.focus();
        }
    });
    tabs.forEach(tab => tab.addEventListener('shown.bs.tab', () => {
        reveal(tab);
        if (window.history && window.history.replaceState) {
            const url = new URL(window.location.href);
            url.hash = tab.getAttribute('data-hub-tab');
            window.history.replaceState(window.history.state, '', url.toString());
        }
    }));
};

/**
 * Show an edge fade on the tab row only while there are more tabs to scroll to in that direction.
 *
 * @returns {void}
 */
const bindTabsOverflow = () => {
    const wrap = document.querySelector('#googlemeet-recording-hub .googlemeet-hub-tabs-wrap');
    const strip = wrap ? wrap.querySelector('.googlemeet-hub-tabs') : null;
    if (!strip) {
        return;
    }
    const update = () => {
        const max = strip.scrollWidth - strip.clientWidth;
        wrap.classList.toggle('googlemeet-tabs-more-start', max > 1 && strip.scrollLeft > 1);
        wrap.classList.toggle('googlemeet-tabs-more-end', max > 1 && strip.scrollLeft < max - 1);
    };
    strip.addEventListener('scroll', update, {passive: true});
    window.addEventListener('resize', update);
    if (window.ResizeObserver) {
        new window.ResizeObserver(update).observe(strip);
    }
    update();
};

/**
 * Stable storage key for a key point, independent of its position (text prefix, whitespace-normalised).
 *
 * @param {string} text Key point text.
 * @returns {string}
 */
const keypointKey = text => String(text || '').replace(/\s+/g, ' ').trim().slice(0, 120);

/**
 * Read a JSON value from localStorage (per-viewer convenience only; failures are ignored).
 *
 * @param {string} key Storage key.
 * @param {*} fallback Value when missing or unavailable.
 * @returns {*}
 */
const readLocal = (key, fallback) => {
    try {
        const raw = window.localStorage.getItem(key);
        return raw ? JSON.parse(raw) : fallback;
    } catch {
        return fallback;
    }
};

/**
 * Collapse a long summary behind a "Read more" toggle (only when it really overflows).
 *
 * @returns {void}
 */
const bindReadMore = () => {
    const body = document.getElementById('googlemeet-ai-summary-body');
    const toggle = document.querySelector('#googlemeet-recording-hub .googlemeet-readmore');
    if (!body || !toggle) {
        return;
    }
    const measure = () => {
        body.classList.add('googlemeet-collapsed');
        // Collapse only when at least ~30% more text would be hidden; a line or two is not worth a toggle.
        const overflow = body.scrollHeight > body.clientHeight * 1.3;
        if (!overflow) {
            body.classList.remove('googlemeet-collapsed');
            toggle.classList.add('d-none');
            return false;
        }
        toggle.classList.remove('d-none');
        return true;
    };
    const panel = document.getElementById('googlemeet-summary-panel');
    const ready = () => {
        if (body.getAttribute('data-measured') === '1' || (panel && !panel.classList.contains('active'))) {
            return;
        }
        body.setAttribute('data-measured', '1');
        measure();
    };
    ready();
    const summaryTab = document.getElementById('googlemeet-summary-tab');
    if (summaryTab) {
        summaryTab.addEventListener('shown.bs.tab', ready);
    }
    toggle.addEventListener('click', () => {
        const expanded = toggle.getAttribute('aria-expanded') === 'true';
        body.classList.toggle('googlemeet-collapsed', expanded);
        toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        toggle.textContent = toggle.getAttribute(expanded ? 'data-label-more' : 'data-label-less');
        if (expanded) {
            body.closest('section').scrollIntoView({block: 'nearest', behavior: prefersReducedMotion() ? 'auto' : 'smooth'});
        }
    });
};

/**
 * Print summary button: print the lesson notes (the print stylesheet hides player, tabs and actions).
 *
 * @returns {void}
 */
const bindPrintSummary = () => {
    const button = document.querySelector('#googlemeet-recording-hub .googlemeet-print-summary');
    if (!button || typeof window.print !== 'function') {
        return;
    }
    button.classList.remove('d-none');
    const body = document.getElementById('googlemeet-ai-summary-body');
    let wasCollapsed = false;
    window.addEventListener('beforeprint', () => {
        wasCollapsed = !!body && body.classList.contains('googlemeet-collapsed');
        if (wasCollapsed) {
            body.classList.remove('googlemeet-collapsed');
        }
    });
    window.addEventListener('afterprint', () => {
        if (wasCollapsed) {
            body.classList.add('googlemeet-collapsed');
        }
    });
    button.addEventListener('click', () => window.print());
};

/**
 * Key points as a personal review checklist, stored server-side per user and recording (user preference
 * "<hash>:<bits>") so it syncs between devices. Any legacy localStorage state is merged in once.
 *
 * @returns {void}
 */
const bindKeypointChecklist = () => {
    const section = document.querySelector('#googlemeet-recording-hub .googlemeet-ai-keypoints');
    if (!section) {
        return;
    }
    const items = Array.from(section.querySelectorAll('.googlemeet-keypoint'));
    const counter = section.querySelector('.googlemeet-keypoints-progress');
    const storageKey = 'mod_googlemeet_keypoints_' + settings.recordingid;
    const hash = section.getAttribute('data-kp-hash') || '';
    const serverState = section.getAttribute('data-kp-state') || '';
    const keyOf = item => keypointKey(item.querySelector('.googlemeet-keypoint-text').textContent);
    const legacy = new Set(readLocal(storageKey, []));

    const save = () => {
        if (!settings.recordingid || !hash) {
            return Promise.resolve();
        }
        const bits = items.map(item => item.querySelector('.googlemeet-keypoint-check').checked ? '1' : '0').join('');
        return Ajax.call([{
            methodname: 'core_user_set_user_preferences',
            args: {preferences: [{name: 'mod_googlemeet_kp_' + settings.recordingid, value: hash + ':' + bits}]},
        }])[0].catch(() => {
            // Guests or restricted sessions cannot store preferences; the checklist is best effort.
            return;
        });
    };

    const refresh = () => {
        let count = 0;
        items.forEach(item => {
            const checked = item.querySelector('.googlemeet-keypoint-check').checked;
            item.classList.toggle('googlemeet-keypoint-done', checked);
            count += checked ? 1 : 0;
        });
        counter.textContent = (counter.getAttribute('data-template') || '{$a->done}/{$a->total}')
            .replace('{$a->done}', count).replace('{$a->total}', items.length);
        counter.classList.toggle('googlemeet-keypoints-complete', count === items.length);
    };

    let migrate = false;
    items.forEach((item, index) => {
        const box = item.querySelector('.googlemeet-keypoint-check');
        const fromServer = serverState.charAt(index) === '1';
        const fromLegacy = legacy.has(keyOf(item));
        box.checked = fromServer || fromLegacy;
        migrate = migrate || (fromLegacy && !fromServer);
        box.classList.remove('d-none');
        box.addEventListener('change', () => {
            save();
            refresh();
        });
    });
    if (legacy.size > 0) {
        // One-time migration: merge into the server state, then drop the local copy once saved.
        const done = migrate ? save() : Promise.resolve();
        done.then(() => {
            try {
                window.localStorage.removeItem(storageKey);
            } catch {
                return;
            }
            return;
        }).catch(Notification.exception);
    }
    section.classList.add('googlemeet-keypoints-interactive');
    counter.classList.remove('d-none');
    section.querySelector('.googlemeet-keypoints-hint').classList.remove('d-none');
    refresh();
};

/**
 * Run a callback once the document has been parsed (at once when it already has).
 *
 * @param {Function} callback Callback.
 * @returns {void}
 */
const whenDomReady = callback => {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', callback, {once: true});
    } else {
        callback();
    }
};

/**
 * Initialise recording hub behaviours.
 *
 * @param {Object} config Module configuration.
 * @param {number} config.cmid Course module ID.
 * @param {number} config.recordingid Recording ID.
 * @param {boolean} config.caneditrecording Whether recording actions are available.
 * @param {boolean} config.canmanagequestions Whether question management is available.
 * @param {boolean} config.hasquestions Whether questions exist for the recording.
 * @param {boolean} config.progresscompleted Whether this user sees the recording as viewed (measured or marked).
 * @param {boolean} config.progressmeasuredcompleted Whether measured viewing time completed the recording.
 * @param {number} config.progresswatchedseconds Accumulated heartbeat seconds.
 * @param {number} config.progressthresholdseconds Completion threshold in seconds.
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
        caneditrecording: !!config.caneditrecording,
        canmanagequestions: !!config.canmanagequestions,
        hasquestions: !!config.hasquestions,
        progresscompleted: !!config.progresscompleted,
        progressmeasuredcompleted: !!config.progressmeasuredcompleted,
        progresswatchedseconds: parseInt(config.progresswatchedseconds, 10) || 0,
        progressthresholdseconds: parseInt(config.progressthresholdseconds, 10) || 480,
    };
    settings.hubStateKey = 'mod_googlemeet_hub_state_' + settings.cmid + '_' + settings.recordingid;

    loadStrings().then(() => {
        whenDomReady(() => {
            restoreHubState();
            bindTabHash();
            bindTabsOverflow();
            bindQuestionManagement();
            if (settings.caneditrecording) {
                bindRecordingManagement();
            }
            bindRecordingProgress();
            bindChapters();
            bindTimestampLinks();
            bindTranscriptSearch();
            bindReadMore();
            bindKeypointChecklist();
            bindPrintSummary();
            bindPracticePlayer();
        });
        return;
    }).catch(Notification.exception);
};
