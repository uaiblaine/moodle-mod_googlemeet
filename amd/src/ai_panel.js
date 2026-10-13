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
 * Recording AI analysis panel.
 *
 * @module     mod_googlemeet/ai_panel
 * @copyright  2026 PreparaOposiciones
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import * as Notification from 'core/notification';
import Pending from 'core/pending';
import Templates from 'core/templates';
import {getString, getStrings} from 'core/str';
// The 5.2 entry point of the Bootstrap API; 5.3 maps it onto the 'bootstrap' module (theme_boost hook_listener).
import {Modal} from 'theme_boost/index';

const COMPONENT = 'mod_googlemeet';
const TEMPLATE_AI_RESULT = 'mod_googlemeet/local/ai_result';

/** @type {number} Duration of the panel slide, in milliseconds. */
const SLIDE_DURATION = 200;
/** @type {string[]} Box properties a slide animates. */
const SLIDE_PROPERTIES = ['height', 'paddingTop', 'paddingBottom', 'marginTop', 'marginBottom'];

const stringRequests = [
    {key: 'ai_copied', component: COMPONENT},
    {key: 'ai_transcript_unavailable', component: COMPONENT},
    {key: 'ai_generating', component: COMPONENT},
    {key: 'ai_regenerate', component: COMPONENT},
    {key: 'ai_generate', component: COMPONENT},
    {key: 'ai_error_unknown', component: COMPONENT},
    {key: 'ai_status_pending', component: COMPONENT},
    {key: 'ai_analysis_available', component: COMPONENT},
    {key: 'ai_badge_label', component: COMPONENT},
    {key: 'ai_subtitles_unavailable', component: COMPONENT},
    {key: 'ai_transcribe_from_video', component: COMPONENT},
    {key: 'ai_transcribe_from_video_confirm', component: COMPONENT},
    {key: 'ai_processing_background', component: COMPONENT},
    {key: 'ai_processing_background_hint', component: COMPONENT},
    {key: 'ai_check_status', component: COMPONENT},
    {key: 'ai_status_processing', component: COMPONENT},
    {key: 'ai_edit_manual_title', component: COMPONENT},
    {key: 'ai_analyze_empty_transcript', component: COMPONENT},
    {key: 'ai_analyzing', component: COMPONENT},
    {key: 'ai_analyze_success', component: COMPONENT},
    {key: 'ai_edit_saving', component: COMPONENT},
    {key: 'ai_edit_save', component: COMPONENT},
    {key: 'ai_edit_saved', component: COMPONENT},
    {key: 'ai_queued_since', component: COMPONENT},
    {key: 'ai_replace_confirm_title', component: COMPONENT},
    {key: 'ai_replace_confirm_button', component: COMPONENT},
    {key: 'ai_analyze_replace_confirm', component: COMPONENT},
    {key: 'ai_regenerate_replace_confirm', component: COMPONENT},
    {key: 'ai_replace_manual_warning', component: COMPONENT},
    {key: 'error', component: 'core'},
    {key: 'ai_status_stuck_panel', component: COMPONENT},
];

let initialised = false;
let settings = {};
let strings = {};
const loadedTranscripts = {};
/** @type {{recordingid: string, hasanalysis: boolean, manual: boolean}} State of the analysis open in the edit modal. */
let editState = {recordingid: '', hasanalysis: false, manual: false};
const pollingIntervals = {};
/** @type {WeakMap<HTMLElement, {animation: Animation, complete: Function}>} Slide running on each panel. */
const runningSlides = new WeakMap();

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
 * Escape text for safe insertion into HTML strings.
 *
 * Serialises a text node, so it escapes &, < and > (and a no-break space as &nbsp;) but not quotes.
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
 * Format plain multiline text as escaped HTML with line breaks.
 *
 * @param {string} text Raw text.
 * @returns {string}
 */
const formatText = text => {
    if (!text) {
        return '';
    }

    return escapeHtml(text).replace(/\n/g, '<br>');
};

/**
 * First descendant of a root matching a selector; a missing root matches nothing.
 *
 * @param {HTMLElement|null} root Root element.
 * @param {string} selector CSS selector.
 * @returns {HTMLElement|null}
 */
const find = (root, selector) => (root ? root.querySelector(selector) : null);

/**
 * Every descendant of a root matching a selector; a missing root matches nothing.
 *
 * @param {HTMLElement|null} root Root element.
 * @param {string} selector CSS selector.
 * @returns {HTMLElement[]}
 */
const findAll = (root, selector) => (root ? Array.from(root.querySelectorAll(selector)) : []);

/**
 * Hide an element through its inline display.
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
 * Show an element: drop the inline display, and force block when a stylesheet still hides it.
 *
 * @param {HTMLElement|null} element Element.
 * @returns {void}
 */
const show = element => {
    if (!element) {
        return;
    }
    element.style.display = '';
    if (window.getComputedStyle(element).display === 'none') {
        element.style.display = 'block';
    }
};

/**
 * Hide every descendant of a root matching a selector.
 *
 * @param {HTMLElement|null} root Root element.
 * @param {string} selector CSS selector.
 * @returns {void}
 */
const hideAll = (root, selector) => findAll(root, selector).forEach(hide);

/**
 * Whether an element takes up space in the layout.
 *
 * @param {HTMLElement|null} element Element.
 * @returns {boolean}
 */
const isVisible = element => !!element &&
    !!(element.offsetWidth || element.offsetHeight || element.getClientRects().length);

/**
 * Set the disabled state and the text of a button that may be absent.
 *
 * @param {HTMLElement|null} button Button.
 * @param {boolean} disabled Whether the button is disabled.
 * @param {string} text Button text.
 * @returns {void}
 */
const setButton = (button, disabled, text) => {
    if (button) {
        button.disabled = disabled;
        button.textContent = text;
    }
};

/**
 * Parse an HTML string holding one element.
 *
 * @param {string} html Markup.
 * @returns {HTMLElement}
 */
const createElement = html => {
    const holder = document.createElement('div');
    holder.innerHTML = html;
    return holder.firstElementChild;
};

/**
 * Value of a form control by id (undefined when it is absent).
 *
 * @param {string} id Element id.
 * @returns {string|undefined}
 */
const getValue = id => {
    const element = document.getElementById(id);
    return element ? element.value : undefined;
};

/**
 * Set the value of a form control by id, when it exists.
 *
 * @param {string} id Element id.
 * @param {string} value New value.
 * @returns {void}
 */
const setValue = (id, value) => {
    const element = document.getElementById(id);
    if (element) {
        element.value = value;
    }
};

/**
 * Show or hide the manual edit modal.
 *
 * @param {boolean} visible Whether to show it.
 * @returns {void}
 */
const toggleEditModal = visible => {
    const element = document.getElementById('googlemeet-ai-edit-modal');
    if (!element) {
        return;
    }
    const modal = Modal.getOrCreateInstance(element);
    if (visible) {
        modal.show();
    } else {
        modal.hide();
    }
};

/**
 * Slide a panel open or closed, then run a callback.
 *
 * A slide started while another runs on the same element first completes the running one.
 * Behat waits for the slide through core/pending.
 *
 * @param {HTMLElement|null} element Panel.
 * @param {boolean} open Whether to open (slide down) or close (slide up).
 * @param {Function} [done] Called once the slide has ended.
 * @returns {void}
 */
const slide = (element, open, done) => {
    if (!element) {
        return;
    }
    const running = runningSlides.get(element);
    if (running) {
        running.animation.cancel();
        running.complete();
    }

    const pending = new Pending('mod_googlemeet/ai_panel:slide');
    if (open) {
        show(element);
    }
    const computed = window.getComputedStyle(element);
    const expanded = {};
    const collapsed = {};
    SLIDE_PROPERTIES.forEach(property => {
        expanded[property] = computed[property];
        collapsed[property] = '0px';
    });
    const overflow = element.style.overflow;
    element.style.overflow = 'hidden';

    const animation = element.animate(open ? [collapsed, expanded] : [expanded, collapsed], {
        duration: SLIDE_DURATION,
        easing: 'ease-in-out',
    });
    let completed = false;
    const complete = () => {
        if (completed) {
            return;
        }
        completed = true;
        runningSlides.delete(element);
        element.style.overflow = overflow;
        if (!open) {
            hide(element);
        }
        if (done) {
            done();
        }
        pending.resolve();
    };
    runningSlides.set(element, {animation: animation, complete: complete});
    animation.addEventListener('finish', complete);
    animation.addEventListener('cancel', complete);
};

/**
 * Handle clicks on elements matching a selector, delegated from the document.
 *
 * A click on a disabled match is ignored.
 *
 * @param {string} selector CSS selector.
 * @param {Function} handler Called with the matching element and the event.
 * @returns {void}
 */
const delegateClick = (selector, handler) => {
    document.addEventListener('click', event => {
        const element = event.target instanceof Element ? event.target.closest(selector) : null;
        if (element && element.disabled !== true) {
            handler(element, event);
        }
    });
};

/**
 * Find the visible card or list item for a recording.
 *
 * @param {number|string} recordingid Recording ID.
 * @returns {HTMLElement|null}
 */
const getRecordingItem = recordingid => document.querySelector(
    '.googlemeet-recording-card[data-recordingid="' + recordingid + '"], ' +
    '.googlemeet-recording-listitem[data-recordingid="' + recordingid + '"]'
);

/**
 * Call a Moodle external function.
 *
 * @param {string} methodname Web service method name.
 * @param {Object} args Web service arguments.
 * @param {number} [timeout] Optional timeout in milliseconds.
 * @returns {Promise}
 */
const call = (methodname, args, timeout) => {
    const request = {
        methodname: methodname,
        args: args,
    };
    if (timeout) {
        request.timeout = timeout;
    }

    return Ajax.call([request])[0];
};

/**
 * Return a localized key-point count label.
 *
 * @param {number} count Key-point count.
 * @returns {Promise<string>}
 */
const keypointsLabel = count => {
    const key = count === 1 ? 'ai_keypoints_count' : 'ai_keypoints_count_plural';
    return getString(key, COMPONENT, count);
};

/**
 * Return a localized topic overflow label.
 *
 * @param {number} count Overflow count.
 * @returns {Promise<string>}
 */
const topicOverflowLabel = count => getString('recordings_topics_overflow_aria', COMPONENT, count);

/**
 * Build the context expected by mod_googlemeet/local/ai_result.
 *
 * @param {number|string} recordingid Recording ID.
 * @param {Object} data AI analysis data.
 * @returns {Object}
 */
const buildResultContext = (recordingid, data) => {
    const keypoints = data.keypoints || [];

    return {
        id: recordingid,
        hascapability: !!settings.canedit,
        aisummary: data.summary || '',
        aisummaryhtml: formatText(data.summary || ''),
        aikeypoints: keypoints,
        aikeypointscount: keypoints.length,
        aitopics: data.topics || [],
        transcriptloaded: true,
        transcripthtml: formatText(data.transcript || ''),
        aimodel: data.aimodel || '',
    };
};

/**
 * Render the shared AI result partial into the panel content.
 *
 * @param {HTMLElement|null} content Panel content element.
 * @param {Object} context Template context.
 * @returns {Promise<void>}
 */
const renderAiResult = async(content, context) => {
    const result = find(content, '.googlemeet-ai-result');
    const rendered = await Templates.renderForPromise(TEMPLATE_AI_RESULT, context);

    if (result) {
        Templates.replaceNode(result, rendered.html, rendered.js || '');
    } else if (content) {
        Templates.appendNodeContents(content, rendered.html, rendered.js || '');
    }
};

/**
 * Update the list/card presentation after an analysis has completed.
 *
 * @param {Object} data AI analysis data.
 * @param {HTMLElement|null} item Recording card/list item.
 * @param {HTMLElement|null} content Panel content element.
 * @returns {Promise<void>}
 */
const updateRecordingPreview = async(data, item, content) => {
    const isListItem = !!item && item.classList.contains('googlemeet-recording-listitem');
    const keypoints = data.keypoints || [];
    const topics = data.topics || [];
    const countLabel = await keypointsLabel(keypoints.length);

    if (content) {
        content.setAttribute('data-hasai', '1');
    }
    if (!item) {
        return;
    }
    const metaContainer = item.querySelector('.googlemeet-card-meta, .googlemeet-listitem-meta');

    item.classList.add('googlemeet-recording-has-ai');
    item.querySelectorAll('.googlemeet-ai-toggle-btn').forEach(button => {
        button.classList.add('googlemeet-ai-toggle-active');
        button.setAttribute('data-hasai', '1');
    });

    findAll(metaContainer, '.googlemeet-ai-statuschip').forEach(chip => chip.remove());
    if (metaContainer) {
        let keypointsElement = metaContainer.querySelector('.googlemeet-ai-keypoints-count');
        if (!keypointsElement) {
            keypointsElement = document.createElement('span');
            keypointsElement.className = 'googlemeet-ai-keypoints-count';
            metaContainer.append(keypointsElement);
        }
        keypointsElement.textContent = countLabel;
    }

    if (isListItem) {
        await updateListPreview(item, metaContainer, topics);
        return;
    }

    await updateCardPreview(item, data, topics);
};

/**
 * Update a card-view recording preview after AI generation.
 *
 * @param {HTMLElement} item Recording card.
 * @param {Object} data AI analysis data.
 * @param {Array} topics Topic strings.
 * @returns {Promise<void>}
 */
const updateCardPreview = async(item, data, topics) => {
    let summaryPreview = '';
    if (data.summary) {
        summaryPreview = data.summary.length > 200 ? data.summary.substring(0, 200) + '...' : data.summary;
    }
    const cardBody = item.querySelector('.googlemeet-card-body');
    const cardSummary = find(cardBody, '.googlemeet-card-summary');

    if (cardSummary) {
        cardSummary.textContent = summaryPreview;
    } else if (cardBody) {
        cardBody.insertAdjacentHTML('beforeend', '<p class="googlemeet-card-summary">' + escapeHtml(summaryPreview) + '</p>');
    }

    if (topics.length > 0) {
        const topicsHtml = await cardTopicsHtml(topics);
        const cardTopics = find(cardBody, '.googlemeet-card-topics');
        if (cardTopics) {
            cardTopics.outerHTML = topicsHtml;
        } else if (cardBody) {
            cardBody.insertAdjacentHTML('beforeend', topicsHtml);
        }
    } else {
        findAll(cardBody, '.googlemeet-card-topics').forEach(element => element.remove());
    }

    const cardMedia = item.querySelector('.googlemeet-card-media');
    if (!cardMedia) {
        return;
    }
    let cardBadges = cardMedia.querySelector('.googlemeet-card-badges');
    if (!cardBadges) {
        cardBadges = document.createElement('span');
        cardBadges.className = 'googlemeet-card-badges';
        cardMedia.append(cardBadges);
    }
    if (!cardBadges.querySelector('.googlemeet-card-aibadge')) {
        cardBadges.insertAdjacentHTML(
            'beforeend',
            '<span class="googlemeet-card-aibadge" title="' + escapeHtml(strings.ai_analysis_available) + '">' +
            escapeHtml(strings.ai_badge_label) + '</span>'
        );
    }
};

/**
 * Update a list-row recording preview after AI generation.
 *
 * @param {HTMLElement} item Recording list item.
 * @param {HTMLElement|null} metaContainer Metadata container.
 * @param {Array} topics Topic strings.
 * @returns {Promise<void>}
 */
const updateListPreview = async(item, metaContainer, topics) => {
    findAll(metaContainer, '.googlemeet-ai-topic-tag').forEach(tag => tag.remove());
    if (topics.length > 0) {
        const chips = await topicChipsHtml(topics.slice(0, 2), topics.length - 2);
        if (metaContainer) {
            metaContainer.insertAdjacentHTML('beforeend', chips);
        }
    }

    const listTitle = item.querySelector('.googlemeet-listitem-title');
    if (listTitle && !listTitle.querySelector('.googlemeet-card-aibadge')) {
        listTitle.insertAdjacentHTML(
            'beforeend',
            '<span class="googlemeet-card-aibadge" title="' + escapeHtml(strings.ai_analysis_available) + '">' +
            escapeHtml(strings.ai_badge_label) + '</span>'
        );
    }
};

/**
 * Build topic chips for a card.
 *
 * @param {Array} topics Topic strings.
 * @returns {Promise<string>}
 */
const cardTopicsHtml = async(topics) => {
    const visible = topics.slice(0, 2);
    const overflow = topics.length - visible.length;
    return '<div class="googlemeet-card-topics">' + await topicChipsHtml(visible, overflow) + '</div>';
};

/**
 * Build topic chip HTML.
 *
 * @param {Array} topics Visible topic strings.
 * @param {number} overflow Overflow count.
 * @returns {Promise<string>}
 */
const topicChipsHtml = async(topics, overflow) => {
    let html = '';
    topics.forEach(topic => {
        html += '<span class="googlemeet-ai-topic-tag">' + escapeHtml(topic) + '</span>';
    });

    if (overflow > 0) {
        const label = await topicOverflowLabel(overflow);
        html += '<span class="googlemeet-ai-topic-tag googlemeet-topic-overflow" title="' + escapeHtml(label) +
            '" aria-label="' + escapeHtml(label) + '">+' + overflow + '</span>';
    }

    return html;
};

/**
 * Display a completed AI analysis in the panel and list preview.
 *
 * @param {number|string} recordingid Recording ID.
 * @param {Object} data AI analysis response.
 * @param {HTMLElement|null} item Recording card/list item.
 * @param {HTMLElement|null} content Panel content.
 * @returns {Promise<void>}
 */
const displayAiAnalysis = async(recordingid, data, item, content) => {
    hideAll(content, '.googlemeet-ai-nodata');
    hideAll(content, '.googlemeet-ai-loading');
    hideAll(content, '.googlemeet-ai-error');

    await renderAiResult(content, buildResultContext(recordingid, data));
    findAll(content, '.googlemeet-ai-result').forEach(show);
    loadedTranscripts[recordingid] = true;

    await updateRecordingPreview(data, item, content);
};

/**
 * Render a completed analysis, reporting a rendering failure through the notification API.
 *
 * The web service callbacks call this instead of chaining on displayAiAnalysis(), so a rendering
 * error never reaches their own failure handlers (which describe a failed request).
 *
 * @param {number|string} recordingid Recording ID.
 * @param {Object} data AI analysis response.
 * @param {HTMLElement|null} item Recording card/list item.
 * @param {HTMLElement|null} content Panel content.
 * @returns {void}
 */
const showAiAnalysis = (recordingid, data, item, content) => {
    displayAiAnalysis(recordingid, data, item, content).catch(Notification.exception);
};

/**
 * Transcript containers of a recording.
 *
 * @param {number|string} recordingid Recording ID.
 * @returns {HTMLElement[]}
 */
const transcriptElements = recordingid => Array.from(
    document.querySelectorAll('.googlemeet-ai-transcript-text[data-recordingid="' + recordingid + '"]')
);

/**
 * Load a transcript on demand.
 *
 * @param {number|string} recordingid Recording ID.
 * @returns {void}
 */
const loadTranscript = recordingid => {
    call('mod_googlemeet_get_ai_analysis', {
        recordingid: recordingid,
        coursemoduleid: settings.cmid,
    }).then(response => {
        const elements = transcriptElements(recordingid);
        if (response.found && response.transcript) {
            elements.forEach(element => {
                element.innerHTML = formatText(response.transcript);
            });
            loadedTranscripts[recordingid] = true;
        } else {
            elements.forEach(element => {
                element.textContent = strings.ai_transcript_unavailable;
            });
            loadedTranscripts[recordingid] = true;
        }
        return;
    }).catch(() => {
        transcriptElements(recordingid).forEach(element => {
            element.textContent = strings.ai_transcript_unavailable;
        });
    });
};

/**
 * Format elapsed seconds for the generation progress display.
 *
 * @param {number} elapsed Elapsed seconds.
 * @returns {string}
 */
const formatElapsed = elapsed => {
    const minutes = Math.floor(elapsed / 60);
    const seconds = elapsed % 60;
    if (minutes > 0) {
        return minutes + 'm ' + seconds + 's';
    }

    return seconds + 's';
};

/**
 * Panel content of a recording.
 *
 * @param {number|string} recordingid Recording ID.
 * @returns {HTMLElement|null}
 */
const getContent = recordingid => document.querySelector('.googlemeet-ai-content[data-recordingid="' + recordingid + '"]');

/**
 * Generate button of a recording.
 *
 * @param {number|string} recordingid Recording ID.
 * @returns {HTMLElement|null}
 */
const getGenerateButton = recordingid => document.querySelector('.googlemeet-ai-generate-btn[data-id="' + recordingid + '"]');

/**
 * Generate an AI analysis.
 *
 * @param {number|string} recordingid Recording ID.
 * @param {boolean} regenerate Whether this is a regeneration.
 * @param {boolean} forcedownload Whether full-video transcription is allowed.
 * @returns {void}
 */
const generateAiAnalysis = (recordingid, regenerate, forcedownload) => {
    const content = getContent(recordingid);
    const generateButton = getGenerateButton(recordingid);
    const item = getRecordingItem(recordingid);
    const loadingElements = findAll(content, '.googlemeet-ai-loading');
    const startTime = Date.now();
    let progressDots = 0;

    forcedownload = !!forcedownload;

    hideAll(content, '.googlemeet-ai-nodata');
    hideAll(content, '.googlemeet-ai-result');
    hideAll(content, '.googlemeet-ai-error');
    loadingElements.forEach(show);
    setButton(generateButton, true, strings.ai_generating);

    const progressInterval = setInterval(() => {
        progressDots = (progressDots + 1) % 4;
        const dots = '.'.repeat(progressDots);
        const elapsed = Math.floor((Date.now() - startTime) / 1000);
        loadingElements.forEach(loadingElement => {
            loadingElement.querySelectorAll('p').forEach(paragraph => {
                paragraph.innerHTML = escapeHtml(strings.ai_generating) + dots +
                    '<br><small style="opacity: 0.7;">&#9201; ' + escapeHtml(formatElapsed(elapsed)) + '</small>';
            });
        });
    }, 500);

    call('mod_googlemeet_generate_ai_analysis', {
        recordingid: recordingid,
        coursemoduleid: settings.cmid,
        regenerate: regenerate,
        forcedownload: forcedownload,
    }).then(response => {
        clearInterval(progressInterval);
        hideAll(content, '.googlemeet-ai-loading');
        setButton(generateButton, false, strings.ai_regenerate);

        if (response.status === 'completed') {
            showAiAnalysis(recordingid, response, item, content);
        } else if (response.status === 'failed') {
            showAiError(recordingid, response.error || strings.ai_error_unknown);
        } else if (response.status === 'processing') {
            showProcessingStatus(recordingid, content, generateButton, response.timemodified);
        } else {
            showAiError(recordingid, strings.ai_status_pending);
        }
        return;
    }).catch(ex => {
        clearInterval(progressInterval);
        hideAll(content, '.googlemeet-ai-loading');
        setButton(generateButton, false, strings.ai_generate);

        let errorMessage = '';
        if (ex.message) {
            errorMessage = ex.message;
        } else if (ex.error) {
            errorMessage = ex.error;
        } else if (ex.errorcode) {
            errorMessage = strings.error + ': ' + ex.errorcode;
        } else {
            errorMessage = strings.ai_error_unknown;
        }

        showAiError(recordingid, errorMessage);
    });
};

/**
 * Show an AI error message.
 *
 * @param {number|string} recordingid Recording ID.
 * @param {string} message Error message or special error code.
 * @returns {void}
 */
const showAiError = (recordingid, message) => {
    const content = getContent(recordingid);
    const errorElements = findAll(content, '.googlemeet-ai-error');
    errorElements.forEach(element => {
        element.setAttribute('role', 'alert');
        show(element);
    });
    const alerts = [];
    errorElements.forEach(element => alerts.push(...element.querySelectorAll('.alert')));

    hideAll(content, '.googlemeet-ai-nodata');
    hideAll(content, '.googlemeet-ai-loading');
    hideAll(content, '.googlemeet-ai-result');
    hideAll(content, '.googlemeet-ai-processing');

    if (message === 'ai_subtitles_unavailable') {
        alerts.forEach(alert => {
            const explain = document.createElement('div');
            explain.textContent = strings.ai_subtitles_unavailable;
            const forceButton = document.createElement('button');
            forceButton.type = 'button';
            forceButton.className = 'btn btn-sm btn-warning mt-2 googlemeet-ai-force-video-btn';
            forceButton.setAttribute('data-id', recordingid);
            forceButton.textContent = strings.ai_transcribe_from_video;
            alert.replaceChildren(explain, forceButton);
        });
        return;
    }

    alerts.forEach(alert => {
        alert.textContent = message;
    });
};

/**
 * Show the background-processing status and start polling.
 *
 * @param {number|string} recordingid Recording ID.
 * @param {HTMLElement|null} content Panel content.
 * @param {HTMLElement|null} generateButton Generate button.
 * @param {number} [queuedat] Unix time the analysis was queued (shown as "queued since hh:mm").
 * @returns {void}
 */
const showProcessingStatus = (recordingid, content, generateButton, queuedat) => {
    hideAll(content, '.googlemeet-ai-nodata');
    hideAll(content, '.googlemeet-ai-loading');
    hideAll(content, '.googlemeet-ai-result');
    hideAll(content, '.googlemeet-ai-error');

    let processingElement = find(content, '.googlemeet-ai-processing');
    if (!processingElement && content) {
        processingElement = createElement('<div class="googlemeet-ai-processing alert alert-info">' +
            '<div class="d-flex align-items-center">' +
            '<div class="spinner-border spinner-border-sm me-2" role="status"></div>' +
            '<div><strong>' + escapeHtml(strings.ai_processing_background) + '</strong><br>' +
            '<small>' + escapeHtml(strings.ai_processing_background_hint) + '</small></div>' +
            '</div>' +
            '<button class="btn btn-sm btn-outline-primary mt-2 googlemeet-ai-refresh-btn" data-id="' +
            recordingid + '">' + escapeHtml(strings.ai_check_status) + '</button>' +
            '</div>');
        content.append(processingElement);
    }
    const since = parseInt(queuedat, 10);
    if (processingElement && since > 0) {
        let sinceElement = processingElement.querySelector('.googlemeet-ai-queued-since');
        if (!sinceElement) {
            sinceElement = document.createElement('div');
            sinceElement.className = 'googlemeet-ai-queued-since small mt-1';
            const flex = processingElement.querySelector('.d-flex');
            if (flex) {
                flex.after(sinceElement);
            }
        }
        const time = new Date(since * 1000).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
        sinceElement.textContent = strings.ai_queued_since.split('{$a}').join(time);
    }
    show(processingElement);

    setButton(generateButton, true, strings.ai_status_processing);

    if (pollingIntervals[recordingid]) {
        clearInterval(pollingIntervals[recordingid]);
    }
    pollingIntervals[recordingid] = setInterval(() => {
        checkAnalysisStatus(recordingid).then(status => {
            if (status !== 'processing') {
                clearInterval(pollingIntervals[recordingid]);
                delete pollingIntervals[recordingid];
            }
            return status;
        }).catch(Notification.exception);
    }, 10000);
};

/**
 * Check the current status of an AI analysis and update its panel.
 *
 * @param {number|string} recordingid Recording ID.
 * @returns {Promise} Resolves with the analysis status, 'stuck' when the worker died, or
 *     'error' when the request failed; it does not reject on a failed request.
 */
const checkAnalysisStatus = recordingid => {
    return call('mod_googlemeet_get_ai_analysis', {
        recordingid: recordingid,
        coursemoduleid: settings.cmid,
    }).then(response => {
        const content = getContent(recordingid);
        const item = getRecordingItem(recordingid);
        const generateButton = getGenerateButton(recordingid);

        if (response.found && response.status === 'completed') {
            hideAll(content, '.googlemeet-ai-processing');
            setButton(generateButton, false, strings.ai_regenerate);
            showAiAnalysis(recordingid, response, item, content);
        } else if (response.found && response.status === 'failed') {
            hideAll(content, '.googlemeet-ai-processing');
            setButton(generateButton, false, strings.ai_generate);
            showAiError(recordingid, response.error || strings.ai_error_unknown);
        } else if (response.found && response.status === 'processing' && response.stuck) {
            // The worker died: stop polling and let the teacher retry (regenerate re-queues a stuck row).
            hideAll(content, '.googlemeet-ai-processing');
            setButton(generateButton, false, strings.ai_regenerate);
            showAiError(recordingid, strings.ai_status_stuck_panel);
            return 'stuck';
        }

        return response.status;
    }, () => 'error');
};

/**
 * Readable lesson title for a recording, as shown in the recordings list.
 *
 * Falls back to the raw Drive name stored on the trigger when the row is not on the page.
 *
 * @param {number|string} recordingid Recording ID.
 * @param {string} fallback Fallback name (raw recording name).
 * @returns {string}
 */
const displayTitle = (recordingid, fallback) => {
    const element = find(getRecordingItem(recordingid), '.recording-name-text');
    const title = element ? element.textContent.trim() : '';
    return title || fallback || '';
};

/**
 * Ask the teacher to confirm that the current analysis will be replaced.
 *
 * Uses a Moodle modal (Notification.saveCancelPromise). The promise rejects on cancel.
 *
 * @param {string} message Main confirmation text.
 * @param {boolean} manual Whether the current analysis was edited by hand.
 * @param {HTMLElement} trigger Element that opened the confirmation (focus is returned to it).
 * @returns {Promise}
 */
const confirmReplace = (message, manual, trigger) => {
    let body = '<p>' + escapeHtml(message) + '</p>';
    if (manual) {
        body += '<p class="fw-bold mb-0">' + escapeHtml(strings.ai_replace_manual_warning) + '</p>';
    }
    return Notification.saveCancelPromise(
        strings.ai_replace_confirm_title,
        body,
        strings.ai_replace_confirm_button,
        {triggerElement: trigger}
    );
};

/**
 * Open or close the AI panel of a recording from its toggle button.
 *
 * @param {HTMLElement} button Toggle button.
 * @returns {void}
 */
const togglePanel = button => {
    const recordingid = button.getAttribute('data-id');
    const hasAi = button.getAttribute('data-hasai') === '1';
    const panelRow = document.querySelector('.googlemeet-ai-panel-row[data-recordingid="' + recordingid + '"]');
    const panel = find(panelRow, '.googlemeet-ai-panel');

    if (isVisible(panel)) {
        slide(panel, false, () => {
            panelRow.classList.remove('googlemeet-ai-panel-row-open');
        });
        button.classList.remove('expanded');
        button.setAttribute('aria-expanded', 'false');
        return;
    }

    if (hasAi && !loadedTranscripts[recordingid]) {
        loadTranscript(recordingid);
    }
    if (panelRow) {
        panelRow.classList.add('googlemeet-ai-panel-row-open');
    }
    slide(panel, true);
    button.classList.add('expanded');
    button.setAttribute('aria-expanded', 'true');
};

/**
 * Generate, or confirm and regenerate, the analysis of a recording.
 *
 * @param {HTMLElement} trigger Generate button.
 * @returns {void}
 */
const requestGeneration = trigger => {
    const recordingid = trigger.getAttribute('data-id');
    const content = getContent(recordingid);
    const hasAi = !!content && content.getAttribute('data-hasai') === '1';
    if (!hasAi) {
        generateAiAnalysis(recordingid, false);
        return;
    }
    // Regenerating replaces summary, key points, topics and chapters: confirm first,
    // and warn explicitly when the current analysis was edited by hand (aimodel = 'manual').
    const ask = manual => confirmReplace(strings.ai_regenerate_replace_confirm, manual, trigger)
        .then(() => generateAiAnalysis(recordingid, true))
        .catch(() => null);
    call('mod_googlemeet_get_ai_analysis', {
        recordingid: recordingid,
        coursemoduleid: settings.cmid,
    }).then(response => ask(!!response.found && response.aimodel === 'manual'))
        .catch(() => ask(false));
};

/**
 * Confirm, then transcribe a recording from its full video.
 *
 * @param {HTMLElement} button Force-video button.
 * @returns {void}
 */
const requestVideoTranscription = button => {
    const recordingid = button.getAttribute('data-id');
    Notification.saveCancelPromise(
        strings.ai_transcribe_from_video,
        strings.ai_transcribe_from_video_confirm,
        strings.ai_transcribe_from_video,
        {triggerElement: button}
    ).then(() => {
        generateAiAnalysis(recordingid, true, true);
        return;
    }).catch(() => {
        return;
    });
};

/**
 * Copy the transcript of a recording to the clipboard.
 *
 * @param {HTMLElement} button Copy button.
 * @returns {void}
 */
const copyTranscript = button => {
    const recordingid = button.getAttribute('data-id');
    const transcriptText = transcriptElements(recordingid).map(element => element.textContent).join('');

    navigator.clipboard.writeText(transcriptText).then(() => {
        const originalHtml = button.innerHTML;
        button.innerHTML = '&#10003; ' + escapeHtml(strings.ai_copied);
        setTimeout(() => {
            button.innerHTML = originalHtml;
        }, 2000);
        return;
    }).catch(Notification.exception);
};

/**
 * Bind AI panel toggle/generation/copy events.
 *
 * @returns {void}
 */
const bindPanelEvents = () => {
    delegateClick('.googlemeet-ai-toggle-btn', togglePanel);
    delegateClick('.googlemeet-ai-generate-btn', requestGeneration);
    delegateClick('.googlemeet-ai-refresh-btn', button => {
        checkAnalysisStatus(button.getAttribute('data-id')).catch(Notification.exception);
    });
    delegateClick('.googlemeet-ai-force-video-btn', requestVideoTranscription);
    delegateClick('.googlemeet-ai-copy-btn', (button, event) => {
        event.stopPropagation();
        copyTranscript(button);
    });
};

/**
 * Open the manual edit modal for a recording and load its stored analysis.
 *
 * @param {HTMLElement} button Edit button.
 * @returns {void}
 */
const openEditModal = button => {
    const recordingid = button.getAttribute('data-id');
    const recordingname = displayTitle(recordingid, button.getAttribute('data-recordingname') || '');

    editState = {recordingid: recordingid, hasanalysis: false, manual: false};
    setValue('googlemeet-ai-edit-recordingid', recordingid);
    setValue('googlemeet-ai-edit-summary', '');
    setValue('googlemeet-ai-edit-keypoints', '');
    setValue('googlemeet-ai-edit-topics', '');
    setValue('googlemeet-ai-edit-transcript', '');
    const title = document.getElementById('googlemeet-ai-edit-modal-title');
    if (title) {
        title.textContent = strings.ai_edit_manual_title + ': ' + recordingname;
    }

    call('mod_googlemeet_get_ai_analysis', {
        recordingid: recordingid,
        coursemoduleid: settings.cmid,
    }).then(response => {
        if (response.found && response.status === 'completed' && editState.recordingid === recordingid) {
            editState.hasanalysis = true;
            editState.manual = response.aimodel === 'manual';
            setValue('googlemeet-ai-edit-summary', response.summary || '');
            setValue('googlemeet-ai-edit-keypoints', (response.keypoints || []).join('\n'));
            setValue('googlemeet-ai-edit-topics', (response.topics || []).join(', '));
            setValue('googlemeet-ai-edit-transcript', response.transcript || '');
        }
        return;
    }).catch(Notification.exception);

    toggleEditModal(true);
};

/**
 * Run the Gemini transcript analysis and load the result into the edit form.
 *
 * @param {HTMLElement} button Analyze button.
 * @param {string} transcript Transcript text.
 * @returns {void}
 */
const analyzeTranscript = (button, transcript) => {
    const recordingid = getValue('googlemeet-ai-edit-recordingid');
    const originalText = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> ' + escapeHtml(strings.ai_analyzing);

    call('mod_googlemeet_analyze_transcript', {
        transcript: transcript,
        recordingid: parseInt(recordingid, 10),
        coursemoduleid: settings.cmid,
    }, 120000).then(response => {
        button.disabled = false;
        button.innerHTML = originalText;
        if (response.success) {
            editState.hasanalysis = true;
            editState.manual = false;
            setValue('googlemeet-ai-edit-summary', response.summary || '');
            setValue('googlemeet-ai-edit-keypoints', (response.keypoints || []).join('\n'));
            setValue('googlemeet-ai-edit-topics', (response.topics || []).join(', '));
            Notification.addNotification({
                message: strings.ai_analyze_success,
                type: 'success',
            });
        } else {
            Notification.addNotification({
                message: response.error || strings.ai_error_unknown,
                type: 'error',
            });
        }
        return;
    }).catch(ex => {
        button.disabled = false;
        button.innerHTML = originalText;
        Notification.exception(ex);
    });
};

/**
 * Analyse the transcript typed in the edit modal, confirming first when content would be replaced.
 *
 * @param {HTMLElement} button Analyze button.
 * @returns {void}
 */
const requestTranscriptAnalysis = button => {
    const transcript = getValue('googlemeet-ai-edit-transcript');

    if (!transcript.trim()) {
        Notification.addNotification({
            message: strings.ai_analyze_empty_transcript,
            type: 'warning',
        });
        return;
    }

    // The analyze WS saves the new summary/key points/topics straight away: when there is
    // something to lose (a stored analysis or text typed in the form), confirm first.
    const formHasContent = ['googlemeet-ai-edit-summary', 'googlemeet-ai-edit-keypoints', 'googlemeet-ai-edit-topics']
        .some(id => (getValue(id) || '').trim() !== '');
    if (!editState.hasanalysis && !formHasContent) {
        analyzeTranscript(button, transcript);
        return;
    }
    confirmReplace(strings.ai_analyze_replace_confirm, editState.manual, button)
        .then(() => analyzeTranscript(button, transcript))
        .catch(() => null);
};

/**
 * Save the analysis typed in the edit modal, then reload the page.
 *
 * @param {HTMLElement} button Save button.
 * @returns {void}
 */
const saveManualAnalysis = button => {
    const recordingid = getValue('googlemeet-ai-edit-recordingid');

    setButton(button, true, strings.ai_edit_saving);
    call('mod_googlemeet_save_ai_analysis', {
        recordingid: parseInt(recordingid, 10),
        coursemoduleid: settings.cmid,
        summary: getValue('googlemeet-ai-edit-summary'),
        keypoints: getValue('googlemeet-ai-edit-keypoints'),
        topics: getValue('googlemeet-ai-edit-topics'),
        transcript: getValue('googlemeet-ai-edit-transcript'),
    }).then(response => {
        setButton(button, false, strings.ai_edit_save);
        if (response.success) {
            toggleEditModal(false);
            Notification.addNotification({
                message: strings.ai_edit_saved,
                type: 'success',
            });
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        }
        return;
    }).catch(ex => {
        setButton(button, false, strings.ai_edit_save);
        Notification.exception(ex);
    });
};

/**
 * Bind the manual AI-edit modal events.
 *
 * @returns {void}
 */
const bindManualEditEvents = () => {
    delegateClick('.googlemeet-ai-edit-btn', (button, event) => {
        event.stopPropagation();
        openEditModal(button);
    });

    // Bound directly, as before: the modal is rendered once with the page.
    const analyzeButton = document.getElementById('googlemeet-ai-analyze-transcript');
    if (analyzeButton) {
        analyzeButton.addEventListener('click', () => requestTranscriptAnalysis(analyzeButton));
    }
    const saveButton = document.getElementById('googlemeet-ai-edit-save');
    if (saveButton) {
        saveButton.addEventListener('click', () => saveManualAnalysis(saveButton));
    }
};

/**
 * Run a callback once the document has been parsed.
 *
 * @param {Function} callback Callback.
 * @returns {void}
 */
const whenReady = callback => {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', callback, {once: true});
    } else {
        callback();
    }
};

/**
 * Initialise AI panel behaviours.
 *
 * @param {Object} config Module configuration.
 * @param {number} config.cmid Course module ID.
 * @param {boolean} config.canedit Whether the current user can edit recordings.
 * @param {boolean} config.aienabled Whether AI features are enabled.
 * @param {boolean} config.cangenerateai Whether the current user can generate AI.
 * @returns {void}
 */
export const init = config => {
    if (initialised) {
        return;
    }
    initialised = true;
    settings = {
        cmid: parseInt(config.cmid, 10),
        canedit: !!config.canedit,
        aienabled: !!config.aienabled,
        cangenerateai: !!config.cangenerateai,
    };

    loadStrings().then(() => {
        whenReady(() => {
            if (settings.aienabled) {
                bindPanelEvents();
            }
            if (settings.canedit) {
                bindManualEditEvents();
            }
        });
        return;
    }).catch(Notification.exception);
};
