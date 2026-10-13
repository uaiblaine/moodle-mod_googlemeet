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
 * Activity-wide practice session ("review my failed questions" / "practise by topic").
 *
 * Mirrors the recording hub player (same markup and CSS classes) but loads questions from
 * several recordings; each answer is checked with the per-recording WS, which also stores
 * the attempt.
 *
 * @module     mod_googlemeet/practice_session
 * @copyright  2026 PreparaOposiciones
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {getStrings} from 'core/str';

const COMPONENT = 'mod_googlemeet';
const LETTERS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];

const stringKeys = [
    'practice_correct',
    'practice_incorrect',
    'practice_correct_answer',
    'practice_finish',
    'practice_next',
    'practice_round_review',
    'practice_right_option',
    'practice_your_answer',
    'practice_session_from',
    'ai_error_unknown',
];

const escapeHtml = value => {
    const div = document.createElement('div');
    div.textContent = value || '';
    return div.innerHTML;
};

/**
 * Initialise the practice session.
 *
 * @param {Object} config Page configuration.
 * @param {number} config.cmid Course module id.
 * @param {string} config.mode failed|topic.
 * @param {string} config.topic Topic (topic mode).
 * @param {string} config.hubbaseurl Activity view URL (the hub is view.php?id=..&recording=..).
 */
export const init = async(config) => {
    const player = document.querySelector('.googlemeet-practice-session-player');
    if (!player || player.getAttribute('data-initialised') === '1') {
        return;
    }
    player.setAttribute('data-initialised', '1');

    const values = await getStrings(stringKeys.map(key => ({key, component: COMPONENT})));
    const strings = {};
    stringKeys.forEach((key, i) => {
        strings[key] = values[i];
    });

    const state = {
        questions: [],
        queue: [],
        index: 0,
        checked: false,
        correct: 0,
        wrong: [],
        reviewing: false,
    };

    // Act on every match of a selector inside the player, not only the first.
    const findAll = selector => Array.from(player.querySelectorAll(selector));
    const find = selector => player.querySelector(selector);
    const addClass = (selector, ...classes) => findAll(selector).forEach(el => el.classList.add(...classes));
    const removeClass = (selector, ...classes) => findAll(selector).forEach(el => el.classList.remove(...classes));
    const setDisabled = (selector, disabled) => findAll(selector).forEach(el => {
        el.disabled = disabled;
    });
    const focus = selector => {
        const el = find(selector);
        if (el) {
            el.focus();
        }
    };

    const showError = message => {
        addClass('.googlemeet-practice-loading', 'd-none');
        findAll('.googlemeet-practice-error').forEach(el => {
            el.classList.remove('d-none');
            el.textContent = message || strings.ai_error_unknown;
        });
    };

    const updateHeader = answered => {
        const total = state.queue.length;
        const current = Math.min(state.index + 1, total);
        const done = state.index + (answered ? 1 : 0);
        const label = (player.getAttribute('data-progress-tpl') || '{$a->current} / {$a->total}')
            .replace('{$a->current}', current)
            .replace('{$a->total}', total);
        findAll('.googlemeet-practice-progress').forEach(el => {
            el.textContent = (state.reviewing ? strings.practice_round_review + ' · ' : '') + label;
        });
        findAll('.googlemeet-practice-livescore').forEach(live => {
            live.classList.remove('d-none');
            live.textContent = (live.getAttribute('data-template') || '__a__').replace('__a__', state.correct);
        });
        const pct = total ? Math.round((done / total) * 100) : 0;
        findAll('.googlemeet-practice-meter').forEach(meter => {
            meter.classList.remove('d-none');
            meter.setAttribute('aria-valuenow', pct);
            meter.querySelectorAll('.progress-bar').forEach(bar => {
                bar.style.width = pct + '%';
            });
        });
    };

    const renderQuestion = () => {
        const question = state.queue[state.index];
        let optionsHtml = '';
        state.checked = false;
        addClass('.googlemeet-practice-loading, .googlemeet-practice-complete, .googlemeet-practice-error, ' +
            '.googlemeet-practice-empty', 'd-none');
        removeClass('.googlemeet-practice-question', 'd-none');
        removeClass('.googlemeet-practice-options', 'googlemeet-practice-options-answered');
        updateHeader(false);

        const sourceUrl = new URL(config.hubbaseurl, window.location.href);
        sourceUrl.searchParams.set('recording', question.recordingid);
        findAll('.googlemeet-practice-source').forEach(source => {
            source.setAttribute('href', sourceUrl.toString());
            source.textContent = strings.practice_session_from.replace('{$a}', question.recordingname);
        });

        // Stem/options are sanitised server-side with format_text() (same contract as the hub player).
        findAll('.googlemeet-practice-stem').forEach(stem => {
            stem.innerHTML = question.stem;
        });
        findAll('.googlemeet-practice-feedback').forEach(feedback => {
            feedback.classList.add('d-none');
            feedback.classList.remove('alert', 'alert-success', 'alert-warning');
            feedback.replaceChildren();
        });
        findAll('.googlemeet-practice-check').forEach(check => {
            check.disabled = true;
            check.classList.remove('d-none');
        });
        addClass('.googlemeet-practice-next', 'd-none');

        question.options.forEach((option, index) => {
            const inputid = 'googlemeet-practice-' + question.questionid + '-' + option.answerid;
            optionsHtml += '<label class="googlemeet-practice-option" for="' + inputid + '">' +
                '<input class="form-check-input googlemeet-practice-answer" type="radio" ' +
                'name="googlemeet-practice-answer" id="' + inputid + '" value="' + option.answerid + '">' +
                '<span class="googlemeet-practice-letter" aria-hidden="true">' + (LETTERS[index] || index + 1) + '</span>' +
                '<span class="googlemeet-practice-option-text">' + option.text + '</span>' +
                '<span class="googlemeet-practice-option-state"></span>' +
                '</label>';
        });
        findAll('.googlemeet-practice-options').forEach(options => {
            options.innerHTML = optionsHtml;
        });
    };

    const showComplete = () => {
        const total = state.queue.length;
        const pct = total ? Math.round((state.correct / total) * 100) : 0;
        const complete = find('.googlemeet-practice-complete');
        let level = 'low';
        if (pct >= 80) {
            level = 'great';
        } else if (pct >= 50) {
            level = 'good';
        }
        updateHeader(true);
        addClass('.googlemeet-practice-question, .googlemeet-practice-loading, .googlemeet-practice-error', 'd-none');
        if (!complete) {
            return;
        }
        complete.classList.remove('d-none', 'googlemeet-practice-level-great', 'googlemeet-practice-level-good',
            'googlemeet-practice-level-low');
        complete.classList.add('googlemeet-practice-level-' + level);
        complete.querySelectorAll('.googlemeet-practice-score-value').forEach(el => {
            el.textContent = pct + '%';
        });
        complete.querySelectorAll('.googlemeet-practice-score-text').forEach(scoreText => {
            scoreText.textContent = (scoreText.getAttribute('data-template') || '{$a->correct}/{$a->total}')
                .replace('{$a->correct}', state.correct)
                .replace('{$a->total}', total);
        });
        complete.querySelectorAll('.googlemeet-practice-message').forEach(message => {
            message.textContent = message.getAttribute('data-' + level) || '';
        });
        complete.querySelectorAll('.googlemeet-practice-review').forEach(review => {
            review.classList.toggle('d-none', state.wrong.length === 0);
            review.textContent = (review.getAttribute('data-template') || '__a__').replace('__a__', state.wrong.length);
        });
        complete.focus();
    };

    const focusStem = () => {
        findAll('.googlemeet-practice-stem').forEach(stem => stem.setAttribute('tabindex', '-1'));
        focus('.googlemeet-practice-stem');
    };

    const startRound = (queue, reviewing) => {
        state.queue = queue.slice();
        state.index = 0;
        state.correct = 0;
        state.wrong = [];
        state.reviewing = reviewing;
        renderQuestion();
        focusStem();
    };

    player.addEventListener('change', event => {
        const answer = event.target instanceof Element ? event.target.closest('.googlemeet-practice-answer') : null;
        if (!answer || !player.contains(answer)) {
            return;
        }
        removeClass('.googlemeet-practice-option', 'googlemeet-practice-option-selected');
        const option = answer.closest('.googlemeet-practice-option');
        if (option) {
            option.classList.add('googlemeet-practice-option-selected');
        }
        setDisabled('.googlemeet-practice-check', false);
    });

    const checkAnswer = () => {
        if (state.checked) {
            return;
        }
        const question = state.queue[state.index];
        const checkedInput = find('.googlemeet-practice-answer:checked');
        const answerid = parseInt(checkedInput ? checkedInput.value : '', 10);
        if (!answerid) {
            return;
        }
        addClass('.googlemeet-practice-error', 'd-none');
        setDisabled('.googlemeet-practice-check', true);
        Ajax.call([{
            methodname: 'mod_googlemeet_check_practice_answer',
            args: {
                recordingid: question.recordingid,
                coursemoduleid: config.cmid,
                questionid: question.questionid,
                answerid: answerid,
            },
        }])[0].then(response => {
            state.checked = true;
            if (response.correct) {
                state.correct++;
            } else {
                state.wrong.push(question);
            }
            setDisabled('.googlemeet-practice-answer', true);
            addClass('.googlemeet-practice-options', 'googlemeet-practice-options-answered');
            let correctText = '';
            findAll('.googlemeet-practice-answer').forEach(input => {
                const option = input.closest('.googlemeet-practice-option');
                if (!option) {
                    return;
                }
                const stateEl = option.querySelector('.googlemeet-practice-option-state');
                if (parseInt(input.value, 10) === parseInt(response.correctanswerid, 10)) {
                    option.classList.add('googlemeet-practice-option-correct');
                    const optionText = option.querySelector('.googlemeet-practice-option-text');
                    correctText = optionText ? optionText.innerHTML : undefined;
                    if (stateEl) {
                        stateEl.innerHTML = '<span aria-hidden="true">&#10003;</span><span class="visually-hidden">' +
                            escapeHtml(strings.practice_right_option) + '</span>';
                    }
                } else if (input.checked) {
                    option.classList.add('googlemeet-practice-option-incorrect');
                    if (stateEl) {
                        stateEl.innerHTML = '<span aria-hidden="true">&#10007;</span><span class="visually-hidden">' +
                            escapeHtml(strings.practice_your_answer) + '</span>';
                    }
                }
            });
            const feedbackClass = response.correct ? 'alert-success' : 'alert-warning';
            const statusText = response.correct ? strings.practice_correct : strings.practice_incorrect;
            const icon = response.correct ? '&#10003;' : '&#10007;';
            findAll('.googlemeet-practice-feedback').forEach(feedback => {
                feedback.classList.remove('d-none', 'alert-success', 'alert-warning');
                feedback.classList.add('alert', feedbackClass);
                feedback.innerHTML = '<div class="fw-bold"><span aria-hidden="true">' + icon + '</span> ' +
                    escapeHtml(statusText) + '</div>' +
                    (response.correct ? '' : '<div class="mt-2"><strong>' + escapeHtml(strings.practice_correct_answer) +
                        '</strong> ' + correctText + '</div>') +
                    (response.explanation ? '<div class="mt-2">' + response.explanation + '</div>' : '');
            });
            updateHeader(true);
            addClass('.googlemeet-practice-check', 'd-none');
            findAll('.googlemeet-practice-next').forEach(next => {
                next.classList.remove('d-none');
                next.textContent = state.index + 1 >= state.queue.length ? strings.practice_finish : strings.practice_next;
            });
            focus('.googlemeet-practice-next');
            return response;
        }).catch(error => {
            setDisabled('.googlemeet-practice-check', false);
            showError(error.message);
        });
    };

    const goNext = () => {
        state.index++;
        if (state.index >= state.queue.length) {
            showComplete();
        } else {
            renderQuestion();
            focusStem();
        }
    };

    findAll('.googlemeet-practice-check').forEach(el => el.addEventListener('click', checkAnswer));
    findAll('.googlemeet-practice-next').forEach(el => el.addEventListener('click', goNext));
    findAll('.googlemeet-practice-review').forEach(el => el.addEventListener('click', () => startRound(state.wrong, true)));
    findAll('.googlemeet-practice-retry').forEach(el => el.addEventListener('click', () => startRound(state.questions, false)));

    Ajax.call([{
        methodname: 'mod_googlemeet_get_practice_session',
        args: {
            coursemoduleid: config.cmid,
            mode: config.mode,
            topic: config.topic || '',
        },
    }])[0].then(response => {
        state.questions = response.questions || [];
        if (!state.questions.length) {
            addClass('.googlemeet-practice-loading', 'd-none');
            removeClass('.googlemeet-practice-empty', 'd-none');
            return response;
        }
        startRound(state.questions, false);
        return response;
    }).catch(error => showError(error.message));
};
