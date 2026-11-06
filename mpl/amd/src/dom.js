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
 * Small DOM and string helpers for mod_mpl.
 *
 * @module     mod_mpl/dom
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getStrings} from 'core/str';

/**
 * Create an element.
 *
 * @param {String} tag e.g. "div.cls1.cls2"
 * @param {Object|null} attrs attributes, "on*" are event handlers, "text"/"html" set content
 * @param {Array|Node|String} children
 * @returns {HTMLElement}
 */
export const h = (tag, attrs = null, children = []) => {
    const [name, ...classes] = tag.split('.');
    const el = document.createElement(name || 'div');
    if (classes.length) {
        el.className = classes.join(' ');
    }
    Object.entries(attrs || {}).forEach(([key, value]) => {
        if (value === null || value === undefined || value === false) {
            return;
        }
        if (key.startsWith('on') && typeof value === 'function') {
            el.addEventListener(key.substring(2).toLowerCase(), value);
        } else if (key === 'text') {
            el.textContent = value;
        } else if (key === 'class') {
            el.className = (el.className + ' ' + value).trim();
        } else if (key === 'value') {
            el.value = value;
        } else if (key === 'checked' || key === 'selected' || key === 'disabled') {
            el[key] = !!value;
        } else if (key === 'dataset') {
            Object.assign(el.dataset, value);
        } else {
            el.setAttribute(key, value === true ? '' : value);
        }
    });
    append(el, children);
    return el;
};

/**
 * Append children (arrays, strings, nodes; null is skipped).
 *
 * @param {HTMLElement} el
 * @param {Array|Node|String} children
 * @returns {HTMLElement}
 */
export const append = (el, children) => {
    (Array.isArray(children) ? children : [children]).flat(Infinity).forEach((child) => {
        if (child === null || child === undefined || child === false) {
            return;
        }
        el.appendChild(typeof child === 'string' || typeof child === 'number'
            ? document.createTextNode(String(child)) : child);
    });
    return el;
};

/**
 * Remove all children.
 *
 * @param {HTMLElement} el
 */
export const clear = (el) => {
    while (el.firstChild) {
        el.removeChild(el.firstChild);
    }
};

/**
 * Load strings of mod_mpl into an object.
 *
 * @param {Array<String>} keys
 * @returns {Promise<Object>}
 */
export const loadStrings = async(keys) => {
    const values = await getStrings(keys.map((key) => ({key, component: 'mod_mpl'})));
    const out = {};
    keys.forEach((key, i) => {
        out[key] = values[i];
    });
    return out;
};

/**
 * Replace {$a} / {$a->x} placeholders.
 *
 * @param {String} text
 * @param {*} a
 * @returns {String}
 */
export const fmt = (text, a) => {
    if (typeof a === 'object' && a !== null) {
        return text.replace(/\{\$a->(\w+)\}/g, (m, k) => (a[k] !== undefined ? a[k] : m));
    }
    return text.replace(/\{\$a\}/g, a);
};

/**
 * Format a number the German/English way (max 2 decimals).
 *
 * @param {Number} n
 * @returns {String}
 */
export const num = (n) => {
    const v = Math.round((Number(n) || 0) * 100) / 100;
    return v.toLocaleString(document.documentElement.lang || undefined, {maximumFractionDigits: 2});
};

// Line icons (paths from Lucide, https://lucide.dev, ISC license), drawn in the current text colour.
const P = (d) => ['path', {d}];
const ICON_SHAPES = {
    play: [['polygon', {points: '6 3 20 12 6 21 6 3', fill: 'currentColor'}]],
    stop: [['rect', {x: 5, y: 5, width: 14, height: 14, rx: 2, fill: 'currentColor'}]],
    check: [P('M20 6 9 17l-5-5')],
    upload: [P('M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4'), P('M17 8l-5-5-5 5'), P('M12 3v12')],
    reset: [P('M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8'), P('M3 3v5h5')],
    undo: [P('M3 7v6h6'), P('M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13')],
    bulb: [P('M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5'),
        P('M9 18h6'), P('M10 22h4')],
    sparkles: [P('M9.94 15.5A2 2 0 0 0 8.5 14.06l-6.14-1.58a.5.5 0 0 1 0-.96L8.5 9.94A2 2 0 0 0 9.94 8.5'
        + 'l1.58-6.14a.5.5 0 0 1 .96 0L14.06 8.5A2 2 0 0 0 15.5 9.94l6.14 1.58a.5.5 0 0 1 0 .96L15.5 14.06'
        + 'a2 2 0 0 0-1.44 1.44l-1.58 6.14a.5.5 0 0 1-.96 0z'),
        P('M20 3v4'), P('M22 5h-4'), P('M4 17v2'), P('M5 18H3')],
    passed: [['circle', {cx: 12, cy: 12, r: 10}], P('m9 12 2 2 4-4')],
    failed: [['circle', {cx: 12, cy: 12, r: 10}], P('m15 9-6 6'), P('m9 9 6 6')],
    partial: [['circle', {cx: 12, cy: 12, r: 10}], ['path', {d: 'M12 2a10 10 0 0 1 0 20z', fill: 'currentColor'}]],
    error: [P('m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3'), P('M12 9v4'), P('M12 17h.01')],
    config: [P('M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3'
        + 'l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z')],
    lock: [['rect', {x: 3, y: 11, width: 18, height: 11, rx: 2}], P('M7 11V7a5 5 0 0 1 10 0v4')],
    close: [P('M18 6 6 18'), P('m6 6 12 12')],
    right: [P('m9 18 6-6-6-6')],
    down: [P('m6 9 6 6 6-6')],
    up: [P('m5 12 7-7 7 7'), P('M12 19V5')],
    arrowdown: [P('M12 5v14'), P('m19 12-7 7-7-7')],
    copy: [['rect', {x: 8, y: 8, width: 14, height: 14, rx: 2}], P('M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2')],
    trash: [P('M3 6h18'), P('M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6'), P('M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2')],
    file: [P('M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z'), P('M14 2v4a2 2 0 0 0 2 2h4'), P('M10 9H8'),
        P('M16 13H8'), P('M16 17H8')],
    download: [P('M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4'), P('m7 10 5 5 5-5'), P('M12 15V3')],
    comment: [P('M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z')],
    shield: [P('M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72'
        + 'a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z')],
    pencil: [P('M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622'
        + 'l4.353-1.32a2 2 0 0 0 .83-.497z'), P('m15 5 4 4')],
    plus: [P('M5 12h14'), P('M12 5v14')],
};

/**
 * Inline SVG icon.
 *
 * @param {String} name key of ICON_SHAPES
 * @returns {SVGElement}
 */
export const icon = (name) => {
    const ns = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(ns, 'svg');
    const attrs = {'class': 'mpl-svg mpl-svg-' + name, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor',
        'stroke-width': 2, 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true', focusable: 'false'};
    Object.entries(attrs).forEach(([k, v]) => svg.setAttribute(k, v));
    (ICON_SHAPES[name] || []).forEach(([tag, a]) => {
        const el = document.createElementNS(ns, tag);
        Object.entries(a).forEach(([k, v]) => el.setAttribute(k, v));
        svg.appendChild(el);
    });
    return svg;
};
