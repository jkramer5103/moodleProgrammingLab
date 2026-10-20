// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Student workspace: editor, interactive console, check, submit, attempts.
 *
 * @module     mod_mpl/workspace
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {exception as displayException, saveCancelPromise} from 'core/notification';
import {createEditor} from 'mod_mpl/codemirror';
import {h, clear, loadStrings, fmt} from 'mod_mpl/dom';
import {render as renderResult} from 'mod_mpl/results';

const KEYS = [
    'ws_run', 'ws_stop', 'ws_check', 'ws_submit', 'ws_reset', 'ws_resetconfirm', 'ws_console', 'ws_results',
    'ws_attempts', 'ws_saved', 'ws_saving', 'ws_unsaved', 'ws_running', 'ws_finished', 'ws_finishedcode',
    'ws_inputplaceholder', 'ws_consoleempty', 'ws_submitconfirm', 'ws_submitconfirmlimited', 'ws_submittitle',
    'ws_checking', 'ws_submitting', 'ws_noresults', 'ws_noattempts', 'ws_loadcode', 'ws_loadcodeconfirm',
    'ws_viewresult', 'ws_clear', 'ws_starting', 'ws_checkgradedhint', 'ws_shortcuts', 'ws_send',
    'ws_attemptrow', 'ws_connectionlost',
];

const call = (methodname, args) => Ajax.call([{methodname, args}])[0];

/**
 * Debounce helper.
 *
 * @param {Function} fn
 * @param {Number} ms
 * @returns {Function}
 */
const debounce = (fn, ms) => {
    let t = null;
    const wrapped = (...args) => {
        clearTimeout(t);
        t = setTimeout(() => fn(...args), ms);
    };
    wrapped.flush = () => {
        clearTimeout(t);
        fn();
    };
    return wrapped;
};

/**
 * Read the JSON config next to the container.
 *
 * @param {String} selector
 * @returns {Array}
 */
const readConfig = (selector) => {
    const root = document.querySelector(selector);
    const script = document.getElementById(root.id + '-config');
    return [root, JSON.parse(script.textContent)];
};

class Workspace {
    constructor(root, config, S) {
        this.root = root;
        this.config = config;
        this.S = S;
        this.session = null;
        this.offset = 0;
        this.busy = false;
        this.dirty = false;
        this.build();
    }

    build() {
        const S = this.S;
        const c = this.config;
        this.buttons = {};
        const btn = (key, cls, label, handler, title) => {
            this.buttons[key] = h('button.btn.' + cls, {type: 'button', onClick: handler, title: title || null}, label);
            return this.buttons[key];
        };
        const toolbar = h('div.mpl-toolbar', null, [
            c.allowrun ? btn('run', 'btn-success', '▶ ' + S.ws_run, () => this.toggleRun(), 'Ctrl+Enter') : null,
            c.allowcheck ? btn('check', 'btn-primary', '✓ ' + S.ws_check, () => this.check(), 'Ctrl+Shift+Enter') : null,
            c.cansubmit && !c.checkgraded ? btn('submit', 'btn-warning', '⬆ ' + S.ws_submit, () => this.submit()) : null,
            h('span.mpl-toolbar-spacer'),
            this.status = h('span.mpl-save-status.text-muted.small', {text: ''}),
            btn('reset', 'btn-outline-secondary.btn-sm', '⟲ ' + S.ws_reset, () => this.reset()),
        ]);
        if (c.cansubmit && c.submitblocker && this.buttons.submit) {
            this.buttons.submit.disabled = true;
            this.buttons.submit.title = c.submitblocker;
        }
        const hints = [];
        if (c.checkgraded) {
            hints.push(h('div.alert.alert-info.py-2.small', {text: S.ws_checkgradedhint}));
        }
        if (c.submitblocker && c.cansubmit) {
            hints.push(h('div.alert.alert-warning.py-2.small', {text: c.submitblocker}));
        }

        this.editorEl = h('div.mpl-editor');
        this.editor = createEditor(this.editorEl, {
            doc: c.code,
            minHeight: '420px',
            onChange: () => this.changed(),
        });
        this.editor.view.contentDOM.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                if (e.shiftKey) {
                    this.check();
                } else {
                    this.toggleRun();
                }
            }
        });

        // Side panel with tabs.
        this.tabs = {};
        this.panes = {};
        const tabbar = h('div.mpl-tabs', {role: 'tablist'});
        const panes = h('div.mpl-panes');
        const addTab = (key, label) => {
            this.tabs[key] = h('button.mpl-tab', {type: 'button', role: 'tab', onClick: () => this.showTab(key)}, label);
            this.panes[key] = h('div.mpl-pane', {role: 'tabpanel'});
            tabbar.appendChild(this.tabs[key]);
            panes.appendChild(this.panes[key]);
        };
        if (c.allowrun) {
            addTab('console', S.ws_console);
        }
        addTab('results', S.ws_results);
        if (c.cansubmit) {
            addTab('attempts', S.ws_attempts);
        }

        // Console.
        if (c.allowrun) {
            this.consoleOut = h('div.mpl-console-output', {'aria-live': 'polite'},
                h('span.mpl-console-hint', {text: S.ws_consoleempty}));
            this.consoleInput = h('input.mpl-console-input', {type: 'text', placeholder: S.ws_inputplaceholder,
                disabled: true, autocomplete: 'off', spellcheck: 'false',
                onKeydown: (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        this.sendInput();
                    }
                }});
            this.consoleState = h('span.mpl-console-state.small', {text: ''});
            const consoleEl = h('div.mpl-console', {onClick: (e) => {
                if (!this.consoleInput.disabled && e.target.tagName !== 'IMG' && !window.getSelection().toString()) {
                    this.consoleInput.focus();
                }
            }}, [
                this.consoleOut,
                h('div.mpl-console-inputline', null, [h('span.mpl-prompt', {text: '›'}), this.consoleInput,
                    h('button.btn.btn-sm.btn-outline-light.mpl-send', {type: 'button', onClick: () => this.sendInput()},
                        S.ws_send)]),
            ]);
            append(this.panes.console, [
                h('div.mpl-console-bar', null, [this.consoleState, h('span.mpl-toolbar-spacer'),
                    h('button.btn.btn-sm.btn-link', {type: 'button', onClick: () => this.clearConsole()}, S.ws_clear)]),
                consoleEl,
            ]);
        }
        this.resultsEl = h('div.mpl-results', null, h('div.text-muted', {text: S.ws_noresults}));
        this.panes.results.appendChild(this.resultsEl);
        if (c.cansubmit) {
            this.attemptsEl = h('div.mpl-attempts');
            this.panes.attempts.appendChild(this.attemptsEl);
            this.renderAttempts();
        }

        clear(this.root);
        append(this.root, [
            hints,
            toolbar,
            h('div.mpl-split', null, [
                h('div.mpl-split-main', null, [this.editorEl,
                    h('div.mpl-shortcuts.small.text-muted', {text: S.ws_shortcuts})]),
                h('div.mpl-split-side', null, [tabbar, panes]),
            ]),
        ]);
        this.showTab(c.allowrun ? 'console' : 'results');
        this.save = debounce(() => this.saveDraft(), 1500);
        window.addEventListener('beforeunload', () => {
            if (this.dirty) {
                this.save.flush();
            }
        });
    }

    showTab(key) {
        Object.keys(this.tabs).forEach((k) => {
            this.tabs[k].classList.toggle('active', k === key);
            this.tabs[k].setAttribute('aria-selected', k === key ? 'true' : 'false');
            this.panes[k].hidden = k !== key;
        });
    }

    changed() {
        this.dirty = true;
        this.status.textContent = this.S.ws_unsaved;
        this.save();
    }

    async saveDraft() {
        if (!this.dirty) {
            return;
        }
        this.dirty = false;
        this.status.textContent = this.S.ws_saving;
        try {
            await call('mod_mpl_save_draft', {cmid: this.config.cmid, code: this.editor.getValue()});
            if (!this.dirty) {
                this.status.textContent = this.S.ws_saved;
            }
        } catch (e) {
            this.dirty = true;
            this.status.textContent = this.S.ws_unsaved;
        }
    }

    setBusy(flag) {
        this.busy = flag;
        ['check', 'submit', 'reset'].forEach((k) => {
            if (this.buttons[k]) {
                this.buttons[k].disabled = flag || (k === 'submit' && !!this.config.submitblocker);
            }
        });
    }

    // ----- console -------------------------------------------------------

    clearConsole() {
        clear(this.consoleOut);
    }

    write(text, cls) {
        const last = this.consoleOut.lastChild;
        if (last && last.nodeType === 1 && last.dataset.stream === cls && last.tagName === 'SPAN') {
            last.textContent += text;
        } else {
            this.consoleOut.appendChild(h('span.mpl-out-' + cls, {dataset: {stream: cls}, text}));
        }
        this.consoleOut.scrollTop = this.consoleOut.scrollHeight;
    }

    async toggleRun() {
        if (!this.config.allowrun) {
            return;
        }
        if (this.session) {
            this.stopRun();
            return;
        }
        this.showTab('console');
        this.clearConsole();
        const hint = this.consoleOut.querySelector('.mpl-console-hint');
        if (hint) {
            hint.remove();
        }
        this.consoleState.textContent = this.S.ws_starting;
        this.buttons.run.textContent = '■ ' + this.S.ws_stop;
        this.buttons.run.classList.replace('btn-success', 'btn-danger');
        this.dirty = false;
        try {
            const res = await call('mod_mpl_run_start', {cmid: this.config.cmid, code: this.editor.getValue()});
            this.status.textContent = this.S.ws_saved;
            this.session = res.sessionid;
            this.offset = 0;
            this.consoleInput.disabled = false;
            this.consoleInput.value = '';
            this.consoleInput.focus();
            this.consoleState.textContent = this.S.ws_running;
            this.poll(this.session);
        } catch (e) {
            this.finishRun(null);
            displayException(e);
        }
    }

    async poll(sid) {
        let failures = 0;
        while (this.session === sid) {
            let data;
            try {
                const res = await call('mod_mpl_run_poll', {cmid: this.config.cmid, sessionid: sid, offset: this.offset});
                data = JSON.parse(res.json);
                failures = 0;
            } catch (e) {
                failures++;
                if (failures > 3 || (e && e.errorcode === 'sessionnotfound')) {
                    this.write('\n' + this.S.ws_connectionlost + '\n', 'sys');
                    this.finishRun(null);
                    return;
                }
                await new Promise((r) => setTimeout(r, 1000));
                continue;
            }
            if (this.session !== sid) {
                return;
            }
            this.offset = data.offset;
            data.events.forEach((ev) => {
                if (ev.t === 'out' || ev.t === 'err') {
                    this.write(ev.d, ev.t);
                } else if (ev.t === 'plot' && ev.png) {
                    this.consoleOut.appendChild(h('img.mpl-console-plot', {src: 'data:image/png;base64,' + ev.png, alt: 'Plot'}));
                    this.consoleOut.scrollTop = this.consoleOut.scrollHeight;
                }
            });
            if (data.done) {
                this.finishRun(data.exit);
                return;
            }
            if (!data.events.length) {
                await new Promise((r) => setTimeout(r, 150));
            }
        }
    }

    finishRun(exit) {
        this.session = null;
        if (this.consoleInput) {
            this.consoleInput.disabled = true;
        }
        if (this.buttons.run) {
            this.buttons.run.textContent = '▶ ' + this.S.ws_run;
            this.buttons.run.classList.replace('btn-danger', 'btn-success');
        }
        if (exit) {
            let text = exit.code === null || exit.code === undefined ? this.S.ws_finished : fmt(this.S.ws_finishedcode, exit.code);
            if (exit.message) {
                text += ' – ' + exit.message;
            }
            this.write('\n' + text + '\n', 'sys');
            this.consoleState.textContent = text;
        } else {
            this.consoleState.textContent = '';
        }
    }

    async stopRun() {
        const sid = this.session;
        if (!sid) {
            return;
        }
        try {
            await call('mod_mpl_run_stop', {cmid: this.config.cmid, sessionid: sid});
        } catch (e) {
            this.finishRun(null);
        }
    }

    async sendInput() {
        if (!this.session) {
            return;
        }
        const text = this.consoleInput.value;
        this.consoleInput.value = '';
        this.write(text + '\n', 'in');
        try {
            await call('mod_mpl_run_input', {cmid: this.config.cmid, sessionid: this.session, data: text + '\n'});
        } catch (e) {
            displayException(e);
        }
    }

    // ----- tests -----------------------------------------------------------

    async check() {
        if (!this.config.allowcheck || this.busy) {
            return;
        }
        if (this.config.checkgraded) {
            this.submit();
            return;
        }
        this.runTests('mod_mpl_check', this.S.ws_checking);
    }

    async submit() {
        if (this.busy || this.config.submitblocker) {
            return;
        }
        const left = this.config.attemptsleft;
        const question = left === null || left === undefined ? this.S.ws_submitconfirm
            : fmt(this.S.ws_submitconfirmlimited, left);
        try {
            await saveCancelPromise(this.S.ws_submittitle, question, this.S.ws_submit);
        } catch (e) {
            return;
        }
        const result = await this.runTests('mod_mpl_submit', this.S.ws_submitting);
        if (result) {
            this.config.attempts.unshift({id: result.submissionid, attempt: result.attempt, grade: result.grade,
                time: new Date().toLocaleString()});
            this.config.attemptsleft = result.attemptsleft;
            if (result.attemptsleft === 0) {
                this.config.submitblocker = '—';
            }
            this.setBusy(false);
            this.renderAttempts();
        }
    }

    async runTests(method, label) {
        this.setBusy(true);
        this.showTab('results');
        clear(this.resultsEl);
        this.resultsEl.appendChild(h('div.mpl-loading', null, [h('span.spinner-border.spinner-border-sm'), ' ', label]));
        this.dirty = false;
        try {
            const res = await call(method, {cmid: this.config.cmid, code: this.editor.getValue()});
            this.status.textContent = this.S.ws_saved;
            const result = JSON.parse(res.json);
            await renderResult(this.resultsEl, result);
            return result;
        } catch (e) {
            clear(this.resultsEl);
            displayException(e);
            return null;
        } finally {
            this.setBusy(false);
        }
    }

    async reset() {
        try {
            await saveCancelPromise(this.S.ws_reset, this.S.ws_resetconfirm, this.S.ws_reset);
        } catch (e) {
            return;
        }
        this.editor.setValue(this.config.starter);
    }

    renderAttempts() {
        const S = this.S;
        clear(this.attemptsEl);
        if (!this.config.attempts.length) {
            this.attemptsEl.appendChild(h('div.text-muted', {text: S.ws_noattempts}));
            return;
        }
        if (this.config.currentgrade) {
            this.attemptsEl.appendChild(h('div.mb-2', {text: this.config.currentgrade}));
        }
        const table = h('table.table.table-sm.mpl-attempt-table', null, h('tbody', null, this.config.attempts.map((a) =>
            h('tr', null, [
                h('td', {text: fmt(S.ws_attemptrow, a.attempt)}),
                h('td', {text: a.time}),
                h('td', {text: a.grade}),
                h('td.text-end', null, [
                    h('button.btn.btn-sm.btn-link', {type: 'button', onClick: () => this.showAttempt(a.id, false)},
                        S.ws_viewresult),
                    h('button.btn.btn-sm.btn-link', {type: 'button', onClick: () => this.showAttempt(a.id, true)},
                        S.ws_loadcode),
                ]),
            ]))));
        this.attemptsEl.appendChild(table);
    }

    async showAttempt(id, loadCode) {
        try {
            const res = await call('mod_mpl_get_submission', {cmid: this.config.cmid, submissionid: id});
            const data = JSON.parse(res.json);
            if (loadCode) {
                try {
                    await saveCancelPromise(this.S.ws_loadcode, this.S.ws_loadcodeconfirm, this.S.ws_loadcode);
                } catch (e) {
                    return;
                }
                this.editor.setValue(data.code);
                return;
            }
            this.showTab('results');
            await renderResult(this.resultsEl, data);
        } catch (e) {
            displayException(e);
        }
    }
}

const append = (el, children) => {
    (Array.isArray(children) ? children : [children]).flat(Infinity).forEach((c) => c && el.appendChild(c));
};

/**
 * Initialise the workspace.
 *
 * @param {String} selector
 */
export const init = async(selector) => {
    const [root, config] = readConfig(selector);
    const S = await loadStrings(KEYS);
    new Workspace(root, config, S);
};

/**
 * Read-only view of an attempt (teacher page).
 *
 * @param {String} selector
 */
export const initSubmission = async(selector) => {
    const [root, config] = readConfig(selector);
    const editorEl = h('div.mpl-editor');
    const resultsEl = h('div.mpl-results');
    append(root, [h('div.mpl-split', null, [h('div.mpl-split-main', null, editorEl),
        h('div.mpl-split-side', null, resultsEl)])]);
    createEditor(editorEl, {doc: config.code, readOnly: true});
    await renderResult(resultsEl, config.result);
};
