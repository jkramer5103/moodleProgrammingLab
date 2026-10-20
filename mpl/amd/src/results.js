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
 * Renders test results (student feedback and teacher view).
 *
 * @module     mod_mpl/results
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {h, clear, loadStrings, fmt, num} from 'mod_mpl/dom';

export const STRING_KEYS = [
    'res_points', 'res_attempt', 'res_attemptsleft', 'res_currentgrade', 'res_input', 'res_expected',
    'res_actual', 'res_output', 'res_errorout', 'res_error', 'res_hidden', 'res_detailshidden', 'res_configerror',
    'res_configerror_student', 'res_instance', 'res_yourplot', 'res_expectedplot', 'res_criterion',
    'res_status_passed', 'res_status_failed', 'res_status_partial', 'res_status_error', 'res_status_config_error',
    'res_notests', 'res_checkmode', 'res_submitmode', 'res_validatemode', 'res_referror', 'res_seed',
    'res_noexpected', 'res_messages', 'res_showall', 'res_grade', 'res_duration', 'type_io', 'type_function',
    'type_class', 'type_file', 'type_plot', 'vis_visible', 'vis_nameonly', 'vis_hidden', 'res_nooutput', 'crit_figure', 'crit_kind', 'crit_count', 'crit_data', 'crit_title',
    'crit_xlabel', 'crit_ylabel', 'crit_legend',
];

let stringsPromise = null;

/**
 * Strings (cached).
 *
 * @returns {Promise<Object>}
 */
export const strings = () => {
    if (!stringsPromise) {
        stringsPromise = loadStrings(STRING_KEYS);
    }
    return stringsPromise;
};

const ICONS = {passed: '✔', failed: '✖', partial: '◐', error: '⚠', config_error: '⚙'};

/**
 * Pre block, marks lines that differ from the other text.
 *
 * @param {String} text
 * @param {String|null} other
 * @param {String} cls
 * @returns {HTMLElement}
 */
const pre = (text, other = null, cls = '') => {
    const el = h('pre.mpl-pre' + (cls ? '.' + cls : ''));
    const lines = String(text === null || text === undefined ? '' : text).replace(/\n$/, '').split('\n');
    const otherLines = other === null ? null : String(other).replace(/\n$/, '').split('\n');
    lines.forEach((line, i) => {
        const differs = otherLines !== null && (otherLines[i] === undefined || otherLines[i].trimEnd() !== line.trimEnd());
        el.appendChild(h('span.mpl-line' + (differs ? '.mpl-line-diff' : ''), {text: line + '\n'}));
    });
    return el;
};

/**
 * Error box with traceback.
 *
 * @param {Object} error
 * @param {Object} S strings
 * @returns {HTMLElement}
 */
const errorBox = (error, S) => {
    const head = h('div.mpl-error-head', null, [h('strong', {text: error.type || S.res_error}),
        error.message ? ': ' + error.message : '']);
    return h('div.mpl-error', null, [head, error.traceback ? pre(error.traceback, null, 'mpl-pre-error') : null]);
};

const plotImages = (plots, S) => {
    if (!plots) {
        return null;
    }
    const col = (title, list) => h('div.mpl-plot-col', null, [
        h('div.mpl-label', {text: title}),
        (list || []).filter(Boolean).map((png) => h('img.mpl-plot-img', {src: 'data:image/png;base64,' + png, alt: title})),
    ]);
    return h('div.mpl-plot-compare', null, [col(S.res_yourplot, plots.student), col(S.res_expectedplot, plots.reference)]);
};

const criteriaTable = (criteria, S) => h('table.mpl-criteria.table.table-sm', null, [
    h('thead', null, h('tr', null, [h('th', {text: S.res_criterion}), h('th', {text: S.res_points}), h('th')])),
    h('tbody', null, criteria.map((c) => h('tr.' + (c.passed ? 'mpl-ok' : 'mpl-bad'), null, [
        h('td', null, [h('span.mpl-icon', {text: c.passed ? ICONS.passed : ICONS.failed}), ' ', S['crit_' + c.key] || c.label]),
        h('td', {text: num(c.awarded) + ' / ' + num(c.points)}),
        h('td.text-muted.small', {text: c.message || ''}),
    ]))),
]);

/**
 * One test instance (one concrete input).
 *
 * @param {Object} test
 * @param {Object} inst
 * @param {Number} index
 * @param {Object} S
 * @param {Boolean} full teacher view
 * @returns {HTMLElement}
 */
const instanceView = (test, inst, index, S, full) => {
    const parts = [];
    const multi = test.instances.length > 1;
    if (multi) {
        parts.push(h('div.mpl-instance-title', null, [
            h('span.mpl-icon.' + (inst.passed ? 'mpl-ok' : 'mpl-bad'), {text: inst.passed ? ICONS.passed : ICONS.failed}),
            ' ' + fmt(S.res_instance, index + 1),
        ]));
    }
    if (inst.inputs_display !== undefined && test.type !== 'plot') {
        parts.push(h('div.mpl-label', {text: S.res_input}), pre(inst.inputs_display));
    } else if (test.type === 'plot' && inst.inputs_display && inst.inputs_display !== '(keine Eingabe)') {
        parts.push(h('div.mpl-label', {text: S.res_input}), pre(inst.inputs_display));
    }
    if (test.type === 'plot') {
        if (inst.criteria) {
            parts.push(criteriaTable(inst.criteria, S));
        }
        parts.push(plotImages(inst.plots, S));
    } else if (inst.expected_display !== null && inst.expected_display !== undefined) {
        parts.push(h('div.mpl-compare', null, [
            h('div', null, [h('div.mpl-label', {text: S.res_expected}), pre(inst.expected_display)]),
            h('div', null, [h('div.mpl-label', {text: S.res_actual}),
                inst.actual_display === null || inst.actual_display === undefined
                    ? h('div.text-muted.small', {text: '—'})
                    : pre(inst.actual_display, inst.passed ? null : inst.expected_display)]),
        ]));
    } else if (inst.actual_display !== null && inst.actual_display !== undefined) {
        parts.push(h('div.mpl-label', {text: S.res_actual}), pre(inst.actual_display));
    }
    if (inst.messages && inst.messages.length) {
        parts.push(h('ul.mpl-messages', null, inst.messages.filter(Boolean).map((m) => h('li', {text: m}))));
    }
    if (inst.error) {
        parts.push(errorBox(inst.error, S));
    }
    if (test.type !== 'io' && inst.stdout) {
        parts.push(h('div.mpl-label', {text: S.res_output}), pre(inst.stdout));
    }
    if (inst.stderr && !inst.error) {
        parts.push(h('div.mpl-label', {text: S.res_errorout}), pre(inst.stderr, null, 'mpl-pre-error'));
    }
    if (full && inst.reference_error) {
        parts.push(h('div.mpl-label', {text: S.res_referror}), errorBox(inst.reference_error, S));
    }
    if (full && inst.check_error) {
        parts.push(errorBox(inst.check_error, S));
    }
    return h('div.mpl-instance' + (inst.passed ? '.mpl-instance-ok' : '.mpl-instance-bad'), null, parts);
};

/**
 * One test card.
 *
 * @param {Object} test
 * @param {Object} S
 * @param {Boolean} full
 * @returns {HTMLElement}
 */
const testView = (test, S, full) => {
    const status = test.status || 'failed';
    const badges = [h('span.badge.bg-light.text-dark.badge-light.mpl-badge', {text: S['type_' + test.type] || test.type})];
    if (full && test.visibility && test.visibility !== 'visible') {
        badges.push(h('span.badge.bg-secondary.badge-secondary.mpl-badge', {text: S['vis_' + test.visibility]}));
    }
    const summary = h('summary.mpl-test-summary', null, [
        h('span.mpl-status-icon.mpl-status-' + status, {text: ICONS[status] || '?', title: S['res_status_' + status]}),
        h('span.mpl-test-name', {text: test.name}),
        badges,
        h('span.mpl-test-points', {text: num(test.awarded) + ' / ' + num(test.points)}),
    ]);
    const body = h('div.mpl-test-body');
    if (test.config_error) {
        body.appendChild(h('div.alert.alert-warning.mb-2', {
            text: typeof test.config_error === 'string' ? S.res_configerror + ' ' + test.config_error : S.res_configerror_student,
        }));
    }
    if (!test.detail) {
        body.appendChild(h('div.text-muted.small', {text: S.res_detailshidden}));
        const errors = (test.instances || []).filter((i) => i.error && i.error.type);
        if (errors.length) {
            body.appendChild(h('div.mpl-error', null, h('strong', {text: errors[0].error.type})));
        }
    } else {
        const instances = test.instances || [];
        // Show failing instances first, all of them if few.
        const order = instances.map((inst, i) => [inst, i]).sort((a, b) => (a[0].passed === b[0].passed ? a[1] - b[1]
            : (a[0].passed ? 1 : -1)));
        const shown = order.slice(0, 3);
        shown.forEach(([inst, i]) => body.appendChild(instanceView(test, inst, i, S, full)));
        if (order.length > shown.length) {
            const rest = h('div.mpl-rest');
            const btn = h('button.btn.btn-link.btn-sm.p-0', {type: 'button', text: fmt(S.res_showall, order.length),
                onClick: () => {
                    btn.remove();
                    order.slice(3).forEach(([inst, i]) => rest.appendChild(instanceView(test, inst, i, S, full)));
                }});
            body.appendChild(btn);
            body.appendChild(rest);
        }
    }
    const open = status !== 'passed';
    return h('details.mpl-test.mpl-test-' + status, {open}, [summary, body]);
};

/**
 * Render a result into a container.
 *
 * @param {HTMLElement} container
 * @param {Object} result filtered result from the server
 * @returns {Promise<void>}
 */
export const render = async(container, result) => {
    const S = await strings();
    clear(container);
    const full = !!result.full;
    const pct = result.max_score > 0 ? Math.round(100 * result.score / result.max_score) : 0;
    const modeLabel = {check: S.res_checkmode, submit: S.res_submitmode, validate: S.res_validatemode}[result.mode] || '';
    const head = h('div.mpl-result-head', null, [
        h('div.mpl-result-title', null, [
            h('strong', {text: modeLabel}),
            result.attempt ? ' · ' + fmt(S.res_attempt, result.attempt) : '',
            result.grade ? ' · ' + S.res_grade + ': ' + result.grade : '',
        ]),
        h('div.mpl-result-score', {text: S.res_points + ': ' + num(result.score) + ' / ' + num(result.max_score)}),
        h('div.progress.mpl-progress', null, h('div.progress-bar' + (pct === 100 ? '.bg-success' : ''), {
            role: 'progressbar', style: 'width: ' + pct + '%', 'aria-valuenow': pct, 'aria-valuemin': 0,
            'aria-valuemax': 100, text: pct + ' %',
        })),
    ]);
    container.appendChild(head);
    if (result.mode === 'submit' && (result.attemptsleft !== undefined && result.attemptsleft !== null)) {
        container.appendChild(h('div.small.text-muted.mb-2', {text: fmt(S.res_attemptsleft, result.attemptsleft)}));
    }
    if (result.currentgrade) {
        container.appendChild(h('div.small.text-muted.mb-2', {text: fmt(S.res_currentgrade, result.currentgrade)}));
    }
    if (!result.tests.length && !result.hidden.count) {
        container.appendChild(h('div.alert.alert-info', {text: S.res_notests}));
    }
    result.tests.forEach((test) => container.appendChild(testView(test, S, full)));
    if (result.hidden && result.hidden.count) {
        container.appendChild(h('div.mpl-test.mpl-hidden-summary', null, [
            h('span.mpl-status-icon', {text: '🔒'}),
            fmt(S.res_hidden, {count: result.hidden.count, passed: result.hidden.passed}),
            h('span.mpl-test-points', {text: num(result.hidden.awarded) + ' / ' + num(result.hidden.points)}),
        ]));
    }
    if (full && (result.seed || result.duration)) {
        container.appendChild(h('div.small.text-muted.mt-2', {
            text: S.res_seed + ': ' + result.seed + ' · ' + S.res_duration + ': ' + num(result.duration) + ' s',
        }));
    }
};
