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
 * Visual test builder for the activity settings form.
 *
 * Teachers assemble tests from building blocks; the result is stored as JSON
 * in the (hidden) "tests" textarea of the form.
 *
 * @module     mod_mpl/testbuilder
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {exception as displayException} from 'core/notification';
import {fromTextarea, createEditor} from 'mod_mpl/codemirror';
import {h, clear, loadStrings, fmt, num} from 'mod_mpl/dom';
import {render as renderResult} from 'mod_mpl/results';

const KEYS = [
    'tb_addtest', 'tb_name', 'tb_type', 'tb_points', 'tb_visibility', 'tb_moveup', 'tb_movedown', 'tb_duplicate',
    'tb_delete', 'tb_deleteconfirm', 'tb_function', 'tb_class', 'tb_filename', 'tb_inputs_io', 'tb_inputs_function',
    'tb_inputs_class', 'tb_addinput', 'tb_addarg', 'tb_calls', 'tb_addcall', 'tb_method', 'tb_target',
    'tb_target_return', 'tb_target_returns', 'tb_target_attr', 'tb_target_str', 'tb_attrname', 'tb_repeat',
    'tb_repeat_help', 'tb_expected', 'tb_expected_reference', 'tb_expected_fixed', 'tb_expected_none',
    'tb_expectedvalue_text', 'tb_expectedvalue_value', 'tb_compare', 'tb_tolerance', 'tb_ignorecase',
    'tb_properties', 'tb_addproperty', 'tb_advanced', 'tb_check', 'tb_check_help', 'tb_timeout', 'tb_timeout_help',
    'kind_fixed', 'kind_int', 'kind_float', 'kind_str', 'kind_choice', 'kind_list', 'kind_bool',
    'tb_value_literal', 'tb_value_text', 'tb_from', 'tb_to', 'tb_decimals', 'tb_charset', 'tb_chars', 'tb_length',
    'tb_options', 'tb_items', 'tb_sorted', 'tb_unique', 'tb_format', 'fmt_spaces', 'fmt_lines', 'fmt_count_lines',
    'fmt_commas', 'cs_lower', 'cs_upper', 'cs_letters', 'cs_digits', 'cs_alnum', 'cs_text', 'cs_custom',
    'cmp_lines', 'cmp_exact', 'cmp_tokens', 'cmp_unordered', 'cmp_unordered_lines', 'cmp_numbers',
    'cmp_numbers_unordered', 'cmp_contains', 'cmp_regex', 'cmp_numeric', 'cmp_string', 'cmp_help_lines',
    'cmp_help_exact', 'cmp_help_tokens', 'cmp_help_unordered', 'cmp_help_unordered_lines', 'cmp_help_numbers',
    'cmp_help_numbers_unordered', 'cmp_help_contains', 'cmp_help_regex', 'cmp_help_numeric', 'cmp_help_string',
    'prop_nonempty', 'prop_type', 'prop_sorted', 'prop_sorted_desc', 'prop_unique', 'prop_length', 'prop_range',
    'prop_contains', 'prop_not_contains', 'prop_regex', 'tb_min', 'tb_max', 'tb_value', 'tb_plotcriteria',
    'crit_figure', 'crit_kind', 'crit_count', 'crit_data', 'crit_title', 'crit_xlabel', 'crit_ylabel', 'crit_legend',
    'crit_equal', 'crit_present', 'tb_plottolerance', 'tb_plot_help', 'tb_total', 'tb_validate', 'tb_validate_help',
    'tb_validateother', 'tb_validatecode', 'tb_validating', 'tb_showjson', 'tb_empty', 'tb_newtest',
    'type_io', 'type_function', 'type_class', 'type_file', 'type_plot', 'vis_visible', 'vis_nameonly', 'vis_hidden',
    'tb_typehelp_io', 'tb_typehelp_function', 'tb_typehelp_class', 'tb_typehelp_file', 'tb_typehelp_plot',
    'tb_noinputs', 'tb_remove', 'tb_expand', 'tb_collapse', 'tb_points_plot', 'tb_vishelp',
];

const TYPES = ['io', 'function', 'class', 'file', 'plot'];
const KINDS = ['fixed', 'int', 'float', 'str', 'choice', 'list', 'bool'];
const ITEM_KINDS = ['int', 'float', 'str', 'choice', 'bool', 'fixed'];
const TEXT_CMP = ['lines', 'exact', 'tokens', 'unordered', 'unordered_lines', 'numbers', 'numbers_unordered',
    'contains', 'regex'];
const VALUE_CMP = ['exact', 'numeric', 'unordered', 'string', 'regex'];
const PROPS = ['nonempty', 'type', 'sorted', 'sorted_desc', 'unique', 'length', 'range', 'contains', 'not_contains',
    'regex'];
const CRITERIA = ['figure', 'kind', 'count', 'data', 'title', 'xlabel', 'ylabel', 'legend'];
const PY_TYPES = ['int', 'float', 'number', 'str', 'bool', 'list', 'tuple', 'dict', 'set', 'none'];

const uid = () => 't' + Math.random().toString(36).slice(2, 9);

const defaultCriteria = () => ({
    figure: {enabled: true, points: 1},
    kind: {enabled: true, points: 1},
    count: {enabled: false, points: 1},
    data: {enabled: true, points: 2},
    title: {enabled: true, points: 1, mode: 'present'},
    xlabel: {enabled: true, points: 0.5, mode: 'present'},
    ylabel: {enabled: true, points: 0.5, mode: 'present'},
    legend: {enabled: false, points: 1, mode: 'present'},
});

const newTest = (type, n, S) => {
    const t = {id: uid(), name: fmt(S.tb_newtest, n), type, points: 1, visibility: 'visible', inputs: [],
        expected: {source: 'reference', value: ''}, compare: {mode: type === 'function' || type === 'class' ? 'exact' : 'lines'},
        properties: [], repeat: 3};
    if (type === 'function') {
        t.function = '';
    }
    if (type === 'class') {
        t.class = '';
        t.calls = [];
        t.target = {type: 'return'};
    }
    if (type === 'file') {
        t.filename = 'ausgabe.txt';
    }
    if (type === 'plot') {
        t.plot = {tolerance: 0.01, criteria: defaultCriteria()};
    }
    return t;
};

const isRandom = (spec) => spec && spec.kind && spec.kind !== 'fixed';

const testHasRandom = (t) => {
    const specs = (t.inputs || []).slice();
    (t.calls || []).forEach((c) => specs.push(...(c.args || [])));
    return specs.some(isRandom);
};

const plotPoints = (t) => Object.values((t.plot && t.plot.criteria) || {})
    .filter((c) => c && c.enabled !== false).reduce((s, c) => s + (Number(c.points) || 0), 0);

class Builder {
    constructor(root, textarea, contextid, S) {
        this.root = root;
        this.textarea = textarea;
        this.contextid = contextid;
        this.S = S;
        let data = {};
        try {
            data = JSON.parse(textarea.value || '{}');
        } catch (e) {
            data = {};
        }
        this.tests = Array.isArray(data.tests) ? data.tests : (Array.isArray(data) ? data : []);
        this.open = new Set();
        if (this.tests.length <= 2) {
            this.tests.forEach((t) => this.open.add(t.id));
        }
        this.render();
    }

    save() {
        this.textarea.value = JSON.stringify({version: 1, tests: this.tests}, null, 1);
        if (this.totalEl) {
            this.totalEl.textContent = fmt(this.S.tb_total, num(this.total()));
        }
    }

    total() {
        return this.tests.reduce((s, t) => s + (t.type === 'plot' ? plotPoints(t) : (Number(t.points) || 0)), 0);
    }

    render() {
        const S = this.S;
        const scrollY = window.scrollY;
        clear(this.root);
        const list = h('div.mpl-tb-list');
        if (!this.tests.length) {
            list.appendChild(h('div.alert.alert-info', {text: S.tb_empty}));
        }
        this.tests.forEach((t, i) => list.appendChild(this.testCard(t, i)));
        const addBar = h('div.mpl-tb-add', null, [
            h('span.me-2.mr-2', {text: S.tb_addtest + ':'}),
            TYPES.map((type) => h('button.btn.btn-outline-primary.btn-sm.me-1.mr-1.mb-1', {type: 'button',
                title: S['tb_typehelp_' + type],
                onClick: () => {
                    const t = newTest(type, this.tests.length + 1, S);
                    this.tests.push(t);
                    this.open.add(t.id);
                    this.save();
                    this.render();
                }}, '+ ' + S['type_' + type])),
        ]);
        this.totalEl = h('div.mpl-tb-total', {text: fmt(S.tb_total, num(this.total()))});
        this.root.appendChild(h('div.mpl-tb', null, [list, addBar, this.totalEl]));
        this.save();
        window.scrollTo(0, scrollY);
    }

    // ----- generic inputs -------------------------------------------------

    field(label, control, help) {
        return h('label.mpl-tb-field', null, [h('span.mpl-tb-label', {text: label}), control,
            help ? h('span.mpl-tb-help.text-muted.small', {text: help}) : null]);
    }

    text(obj, key, attrs = {}, onChange = null) {
        return h('input.form-control.form-control-sm', Object.assign({type: 'text', value: obj[key] === undefined ? '' : String(obj[key]),
            onInput: (e) => {
                obj[key] = e.target.value;
                this.save();
                if (onChange) {
                    onChange(e.target.value);
                }
            }}, attrs));
    }

    number(obj, key, attrs = {}, rerender = false) {
        return h('input.form-control.form-control-sm.mpl-tb-num', Object.assign({type: 'number', step: 'any',
            value: obj[key] === undefined || obj[key] === null ? '' : String(obj[key]),
            onInput: (e) => {
                obj[key] = e.target.value === '' ? '' : Number(e.target.value);
                this.save();
            },
            onChange: () => rerender && this.render()}, attrs));
    }

    select(obj, key, options, rerender = true, onChange = null) {
        return h('select.form-select.form-select-sm.custom-select.custom-select-sm', {onChange: (e) => {
            obj[key] = e.target.value;
            if (onChange) {
                onChange(e.target.value);
            }
            this.save();
            if (rerender) {
                this.render();
            }
        }}, options.map(([value, label]) => h('option', {value, selected: String(obj[key]) === String(value), text: label})));
    }

    check(obj, key, label, rerender = false) {
        return h('label.mpl-tb-check', null, [h('input', {type: 'checkbox', checked: !!obj[key], onChange: (e) => {
            obj[key] = e.target.checked;
            this.save();
            if (rerender) {
                this.render();
            }
        }}), ' ', label]);
    }

    iconBtn(label, title, handler, cls = 'btn-outline-secondary') {
        return h('button.btn.btn-sm.' + cls, {type: 'button', title, 'aria-label': title, onClick: handler}, label);
    }

    // ----- value specifications ------------------------------------------

    valueRow(list, index, literal, context) {
        const S = this.S;
        const spec = list[index];
        const fields = [];
        const kinds = context === 'item' ? ITEM_KINDS : KINDS;
        fields.push(this.select(spec, 'kind', kinds.map((k) => [k, S['kind_' + k]]), true, (kind) => {
            Object.keys(spec).forEach((k) => k !== 'kind' && delete spec[k]);
            Object.assign(spec, {
                fixed: {value: ''}, int: {min: 1, max: 100}, float: {min: 0, max: 10, decimals: 2},
                str: {charset: 'lower', min_len: 1, max_len: 10}, choice: {options: ''},
                list: {min_len: 1, max_len: 10, item: {kind: 'int', min: 0, max: 100}}, bool: {},
            }[kind]);
        }));
        fields.push(...this.kindFields(spec, literal, context));
        const row = h('div.mpl-tb-valuerow', null, [
            context === 'item' ? null : h('span.mpl-tb-index', {text: (index + 1) + '.'}),
            fields,
            context === 'item' ? null : this.iconBtn('✕', S.tb_remove, () => {
                list.splice(index, 1);
                this.save();
                this.render();
            }, 'btn-link.text-danger'),
        ]);
        return row;
    }

    kindFields(spec, literal, context) {
        const S = this.S;
        const f = [];
        switch (spec.kind) {
            case 'fixed':
                f.push(this.text(spec, 'value', {placeholder: literal ? S.tb_value_literal : S.tb_value_text,
                    class: 'mpl-tb-mono mpl-tb-grow'}));
                break;
            case 'int':
            case 'float':
                f.push(h('span.small', {text: S.tb_from}), this.number(spec, 'min'),
                    h('span.small', {text: S.tb_to}), this.number(spec, 'max'));
                if (spec.kind === 'float') {
                    f.push(h('span.small', {text: S.tb_decimals}), this.number(spec, 'decimals', {min: 0, max: 10, step: 1}));
                }
                break;
            case 'str':
                f.push(h('span.small', {text: S.tb_charset}), this.select(spec, 'charset',
                    ['lower', 'upper', 'letters', 'digits', 'alnum', 'text', 'custom'].map((c) => [c, S['cs_' + c]])));
                if (spec.charset === 'custom') {
                    f.push(this.text(spec, 'chars', {placeholder: 'abc123', class: 'mpl-tb-mono'}));
                }
                f.push(h('span.small', {text: S.tb_length}), this.number(spec, 'min_len', {min: 0, step: 1}),
                    h('span.small', {text: '–'}), this.number(spec, 'max_len', {min: 0, step: 1}));
                break;
            case 'choice':
                f.push(this.text(spec, 'options', {placeholder: S.tb_options, class: 'mpl-tb-mono mpl-tb-grow'}));
                break;
            case 'list': {
                f.push(h('span.small', {text: S.tb_length}), this.number(spec, 'min_len', {min: 0, step: 1}),
                    h('span.small', {text: '–'}), this.number(spec, 'max_len', {min: 0, step: 1}));
                spec.item = spec.item || {kind: 'int', min: 0, max: 100};
                const holder = [spec.item];
                f.push(h('div.mpl-tb-sub', null, [
                    h('span.small', {text: S.tb_items}), this.valueRow(holder, 0, true, 'item'),
                    this.check(spec, 'sorted', S.tb_sorted), this.check(spec, 'unique', S.tb_unique),
                    !literal ? h('span.small', {text: S.tb_format}) : null,
                    !literal ? this.select(spec, 'format', ['spaces', 'lines', 'count_lines', 'commas']
                        .map((x) => [x, S['fmt_' + x]]), false) : null,
                ]));
                spec.item = holder[0];
                break;
            }
            default:
                break;
        }
        return f;
    }

    valueList(list, literal, addLabel, context) {
        const box = h('div.mpl-tb-values');
        if (!list.length) {
            box.appendChild(h('div.text-muted.small', {text: this.S.tb_noinputs}));
        }
        list.forEach((_, i) => box.appendChild(this.valueRow(list, i, literal, context)));
        box.appendChild(h('button.btn.btn-link.btn-sm.p-0', {type: 'button', onClick: () => {
            list.push({kind: 'fixed', value: ''});
            this.save();
            this.render();
        }}, '+ ' + addLabel));
        return box;
    }

    // ----- test card ----------------------------------------------------

    testCard(t, i) {
        const S = this.S;
        const isOpen = this.open.has(t.id);
        const move = (delta) => {
            const j = i + delta;
            if (j < 0 || j >= this.tests.length) {
                return;
            }
            [this.tests[i], this.tests[j]] = [this.tests[j], this.tests[i]];
            this.save();
            this.render();
        };
        const header = h('div.mpl-tb-head', null, [
            this.iconBtn(isOpen ? '▾' : '▸', isOpen ? S.tb_collapse : S.tb_expand, () => {
                if (isOpen) {
                    this.open.delete(t.id);
                } else {
                    this.open.add(t.id);
                }
                this.render();
            }, 'btn-link'),
            h('span.mpl-tb-num-label', {text: (i + 1) + '.'}),
            this.text(t, 'name', {class: 'mpl-tb-name', placeholder: S.tb_name, 'aria-label': S.tb_name}),
            h('span.badge.bg-light.text-dark.badge-light', {text: S['type_' + t.type]}),
            t.type === 'plot'
                ? h('span.small.text-muted', {text: fmt(S.tb_points_plot, num(plotPoints(t)))})
                : h('span.mpl-tb-inline', null, [h('span.small', {text: S.tb_points}),
                    this.number(t, 'points', {min: 0, 'aria-label': S.tb_points})]),
            this.select(t, 'visibility', ['visible', 'nameonly', 'hidden'].map((v) => [v, S['vis_' + v]]), false),
            h('span.mpl-toolbar-spacer'),
            this.iconBtn('↑', S.tb_moveup, () => move(-1)),
            this.iconBtn('↓', S.tb_movedown, () => move(1)),
            this.iconBtn('⧉', S.tb_duplicate, () => {
                const copy = JSON.parse(JSON.stringify(t));
                copy.id = uid();
                copy.name = t.name + ' (2)';
                this.tests.splice(i + 1, 0, copy);
                this.open.add(copy.id);
                this.save();
                this.render();
            }),
            this.iconBtn('🗑', S.tb_delete, () => {
                // eslint-disable-next-line no-alert
                if (window.confirm(S.tb_deleteconfirm)) {
                    this.tests.splice(i, 1);
                    this.save();
                    this.render();
                }
            }, 'btn-outline-danger'),
        ]);
        const card = h('div.mpl-tb-card.mpl-tb-type-' + t.type, null, header);
        if (isOpen) {
            card.appendChild(this.testBody(t));
        }
        return card;
    }

    testBody(t) {
        const S = this.S;
        const body = h('div.mpl-tb-body');
        body.appendChild(h('div.mpl-tb-help.text-muted.small.mb-2', {text: S['tb_typehelp_' + t.type]}));
        const literal = t.type === 'function' || t.type === 'class';
        t.inputs = t.inputs || [];

        // What is called.
        if (t.type === 'function') {
            body.appendChild(this.field(S.tb_function, this.text(t, 'function', {class: 'mpl-tb-mono', placeholder: 'encrypt'})));
        }
        if (t.type === 'class') {
            body.appendChild(this.field(S.tb_class, this.text(t, 'class', {class: 'mpl-tb-mono', placeholder: 'Konto'})));
        }
        if (t.type === 'file') {
            body.appendChild(this.field(S.tb_filename, this.text(t, 'filename', {class: 'mpl-tb-mono'})));
        }

        // Inputs.
        const inputLabel = {io: S.tb_inputs_io, file: S.tb_inputs_io, plot: S.tb_inputs_io, function: S.tb_inputs_function,
            class: S.tb_inputs_class}[t.type];
        body.appendChild(h('div.mpl-tb-section', null, [h('div.mpl-tb-section-title', {text: inputLabel}),
            this.valueList(t.inputs, literal, literal ? S.tb_addarg : S.tb_addinput, 'input')]));

        if (t.type === 'class') {
            t.calls = t.calls || [];
            t.target = t.target || {type: 'return'};
            const calls = h('div.mpl-tb-calls');
            t.calls.forEach((call, ci) => {
                call.args = call.args || [];
                calls.appendChild(h('div.mpl-tb-call', null, [
                    h('div.mpl-tb-inline', null, [h('span.mpl-tb-mono', {text: 'obj.'}),
                        this.text(call, 'method', {class: 'mpl-tb-mono', placeholder: S.tb_method}),
                        h('span.mpl-tb-mono', {text: '(…)'}),
                        this.iconBtn('✕', S.tb_remove, () => {
                            t.calls.splice(ci, 1);
                            this.save();
                            this.render();
                        }, 'btn-link.text-danger')]),
                    this.valueList(call.args, true, S.tb_addarg, 'input'),
                ]));
            });
            calls.appendChild(h('button.btn.btn-link.btn-sm.p-0', {type: 'button', onClick: () => {
                t.calls.push({method: '', args: []});
                this.save();
                this.render();
            }}, '+ ' + S.tb_addcall));
            body.appendChild(h('div.mpl-tb-section', null, [h('div.mpl-tb-section-title', {text: S.tb_calls}), calls]));
            body.appendChild(h('div.mpl-tb-inline.mb-2', null, [h('span.small', {text: S.tb_target}),
                this.select(t.target, 'type', ['return', 'returns', 'attr', 'str'].map((x) => [x, S['tb_target_' + x]])),
                t.target.type === 'attr' ? this.text(t.target, 'name', {class: 'mpl-tb-mono', placeholder: S.tb_attrname}) : null]));
        }

        if (testHasRandom(t)) {
            body.appendChild(h('div.mpl-tb-inline.mb-2', null, [h('span.small', {text: S.tb_repeat}),
                this.number(t, 'repeat', {min: 1, max: 20, step: 1}), h('span.small.text-muted', {text: S.tb_repeat_help})]));
        }

        if (t.type === 'plot') {
            body.appendChild(this.plotSection(t));
        } else {
            body.appendChild(this.expectedSection(t, literal));
            body.appendChild(this.propertySection(t, literal));
        }

        // Advanced.
        const adv = h('details.mpl-tb-advanced', {open: !!(t.check || t.timeout)}, [h('summary', {text: S.tb_advanced})]);
        if (t.type !== 'plot') {
            const area = h('textarea.form-control.mpl-tb-mono', {rows: 6, spellcheck: 'false',
                placeholder: 'def check(result, expected, inputs):\n    return result == expected',
                onInput: (e) => {
                    t.check = e.target.value;
                    this.save();
                }});
            area.value = t.check || '';
            adv.appendChild(this.field(S.tb_check, area, S.tb_check_help));
        }
        adv.appendChild(h('div.mpl-tb-inline', null, [h('span.small', {text: S.tb_timeout}),
            this.number(t, 'timeout', {min: 1, max: 120, step: 1}), h('span.small.text-muted', {text: S.tb_timeout_help})]));
        body.appendChild(adv);
        return body;
    }

    expectedSection(t, literal) {
        const S = this.S;
        t.expected = t.expected || {source: 'reference'};
        t.compare = t.compare || {mode: literal ? 'exact' : 'lines'};
        const sec = h('div.mpl-tb-section', null, h('div.mpl-tb-section-title', {text: S.tb_expected}));
        const radios = h('div.mpl-tb-radios', null, ['reference', 'fixed', 'none'].map((src) => h('label.mpl-tb-check', null, [
            h('input', {type: 'radio', name: 'exp-' + t.id, checked: (t.expected.source || 'reference') === src,
                onChange: () => {
                    t.expected.source = src;
                    this.save();
                    this.render();
                }}), ' ', S['tb_expected_' + src]])));
        sec.appendChild(radios);
        if (t.expected.source === 'fixed') {
            const area = h('textarea.form-control.mpl-tb-mono', {rows: literal ? 1 : 3, spellcheck: 'false',
                placeholder: literal ? S.tb_expectedvalue_value : S.tb_expectedvalue_text,
                onInput: (e) => {
                    t.expected.value = e.target.value;
                    this.save();
                }});
            area.value = t.expected.value || '';
            sec.appendChild(area);
        }
        if (t.expected.source !== 'none') {
            const modes = literal ? VALUE_CMP : TEXT_CMP;
            if (!modes.includes(t.compare.mode)) {
                t.compare.mode = modes[0];
            }
            sec.appendChild(h('div.mpl-tb-inline.mt-2', null, [
                h('span.small', {text: S.tb_compare}),
                this.select(t.compare, 'mode', modes.map((m) => [m, S['cmp_' + m]])),
                ['numbers', 'numbers_unordered', 'numeric', 'exact', 'unordered'].includes(t.compare.mode)
                    ? [h('span.small', {text: S.tb_tolerance}), this.number(t.compare, 'tolerance', {min: 0, placeholder: '0'})]
                    : null,
                this.check(t.compare, 'ignore_case', S.tb_ignorecase),
            ]));
            sec.appendChild(h('div.mpl-tb-help.text-muted.small', {text: S['cmp_help_' + t.compare.mode]}));
        }
        return sec;
    }

    propertySection(t) {
        const S = this.S;
        t.properties = t.properties || [];
        const sec = h('div.mpl-tb-section', null, h('div.mpl-tb-section-title', {text: S.tb_properties}));
        t.properties.forEach((p, pi) => {
            const f = [this.select(p, 'type', PROPS.map((x) => [x, S['prop_' + x]]))];
            if (p.type === 'type') {
                f.push(this.select(p, 'value', PY_TYPES.map((x) => [x, x]), false));
                if (!p.value) {
                    p.value = 'int';
                }
            } else if (p.type === 'length' || p.type === 'range') {
                f.push(h('span.small', {text: S.tb_min}), this.number(p, 'min'), h('span.small', {text: S.tb_max}),
                    this.number(p, 'max'));
            } else if (['contains', 'not_contains', 'regex'].includes(p.type)) {
                f.push(this.text(p, 'value', {class: 'mpl-tb-mono mpl-tb-grow', placeholder: S.tb_value}));
            }
            f.push(this.iconBtn('✕', S.tb_remove, () => {
                t.properties.splice(pi, 1);
                this.save();
                this.render();
            }, 'btn-link.text-danger'));
            sec.appendChild(h('div.mpl-tb-valuerow', null, f));
        });
        sec.appendChild(h('button.btn.btn-link.btn-sm.p-0', {type: 'button', onClick: () => {
            t.properties.push({type: 'nonempty'});
            this.save();
            this.render();
        }}, '+ ' + S.tb_addproperty));
        return sec;
    }

    plotSection(t) {
        const S = this.S;
        t.plot = t.plot || {tolerance: 0.01, criteria: defaultCriteria()};
        t.plot.criteria = Object.assign(defaultCriteria(), t.plot.criteria || {});
        const rows = CRITERIA.map((key) => {
            const c = t.plot.criteria[key];
            return h('tr', null, [
                h('td', null, h('label.mpl-tb-check', null, [h('input', {type: 'checkbox', checked: c.enabled !== false,
                    onChange: (e) => {
                        c.enabled = e.target.checked;
                        this.save();
                        this.render();
                    }}), ' ', S['crit_' + key]])),
                h('td', null, this.number(c, 'points', {min: 0, disabled: c.enabled === false}, true)),
                h('td', null, ['title', 'xlabel', 'ylabel', 'legend'].includes(key)
                    ? this.select(c, 'mode', [['present', S.crit_present], ['equal', S.crit_equal]], false) : null),
            ]);
        });
        return h('div.mpl-tb-section', null, [
            h('div.mpl-tb-section-title', {text: S.tb_plotcriteria}),
            h('div.mpl-tb-help.text-muted.small.mb-2', {text: S.tb_plot_help}),
            h('table.table.table-sm.mpl-tb-criteria', null, h('tbody', null, rows)),
            h('div.mpl-tb-inline', null, [h('span.small', {text: S.tb_plottolerance}),
                this.number(t.plot, 'tolerance', {min: 0})]),
        ]);
    }
}

/**
 * Validation panel ("test with reference solution").
 *
 * @param {HTMLElement} root
 * @param {Number} contextid
 * @param {Object} S
 * @param {Function} getReference
 */
const validationPanel = (root, contextid, S, getReference) => {
    const out = h('div.mpl-results.mt-2');
    const otherWrap = h('div.mpl-tb-other', {hidden: true});
    let other = null;
    const run = async(useOther) => {
        clear(out);
        out.appendChild(h('div.mpl-loading', null, [h('span.spinner-border.spinner-border-sm'), ' ', S.tb_validating]));
        const form = root.closest('form');
        const val = (name, dflt) => {
            const el = form.querySelector('[name="' + name + '"]');
            return el && el.value !== '' ? parseInt(el.value, 10) : dflt;
        };
        try {
            const reference = getReference();
            const res = await Ajax.call([{methodname: 'mod_mpl_validate', args: {
                contextid,
                code: useOther && other ? other.getValue() : reference,
                reference,
                tests: form.querySelector('textarea[name="tests"]').value,
                timeout: val('timeout', 30),
                memory: val('memory', 512),
            }}])[0];
            await renderResult(out, JSON.parse(res.json));
        } catch (e) {
            clear(out);
            displayException(e);
        }
    };
    const panel = h('div.mpl-tb-validate', null, [
        h('div.mpl-tb-inline', null, [
            h('button.btn.btn-secondary.btn-sm', {type: 'button', onClick: () => run(false)}, '▶ ' + S.tb_validate),
            h('button.btn.btn-link.btn-sm', {type: 'button', onClick: () => {
                otherWrap.hidden = !otherWrap.hidden;
                if (!other) {
                    other = createEditor(otherWrap.querySelector('.mpl-tb-other-editor'), {doc: '', minHeight: '120px',
                        placeholder: S.tb_validatecode});
                }
            }}, S.tb_validateother),
        ]),
        h('div.small.text-muted', {text: S.tb_validate_help}),
        otherWrap,
        out,
    ]);
    append(otherWrap, [h('div.mpl-tb-other-editor'), h('button.btn.btn-secondary.btn-sm.mt-1', {type: 'button',
        onClick: () => run(true)}, '▶ ' + S.tb_validatecode)]);
    root.appendChild(panel);
};

const append = (el, children) => children.forEach((c) => c && el.appendChild(c));

/**
 * Initialise the builder on the settings form.
 *
 * @param {Number} contextid
 */
export const init = async(contextid) => {
    const root = document.querySelector('[data-region="mpl-testbuilder"]');
    const textarea = document.querySelector('textarea[name="tests"]');
    if (!root || !textarea) {
        return;
    }
    const S = await loadStrings(KEYS);

    // Code editors for starter code and reference solution.
    const editors = {};
    document.querySelectorAll('textarea[data-mpl-code]').forEach((ta) => {
        editors[ta.dataset.mplCode] = fromTextarea(ta, {minHeight: ta.dataset.mplCode === 'reference' ? '220px' : '120px'});
    });

    // Hide the raw JSON (power users can toggle it).
    const row = textarea.closest('.fitem') || textarea.parentNode;
    row.hidden = true;
    const toggle = h('button.btn.btn-link.btn-sm.p-0.mpl-tb-jsontoggle', {type: 'button', onClick: () => {
        row.hidden = !row.hidden;
    }}, S.tb_showjson);

    const builderEl = h('div');
    root.appendChild(builderEl);
    const builder = new Builder(builderEl, textarea, contextid, S);
    textarea.addEventListener('change', () => {
        try {
            const data = JSON.parse(textarea.value);
            builder.tests = Array.isArray(data.tests) ? data.tests : [];
            builder.render();
        } catch (e) {
            // Keep the builder state while the JSON is being edited.
        }
    });
    validationPanel(root, contextid, S, () => (editors.reference ? editors.reference.getValue()
        : document.querySelector('textarea[name="reference"]').value));
    root.appendChild(toggle);
};
