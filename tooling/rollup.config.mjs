// Builds mpl/amd/src/*.js into Moodle AMD modules in mpl/amd/build/*.min.js.
// Each module is built on its own; imports of Moodle modules (core/*, mod_mpl/*)
// stay external so Moodle's RequireJS loader resolves them.
import {readdirSync} from 'node:fs';
import {nodeResolve} from '@rollup/plugin-node-resolve';
import terser from '@rollup/plugin-terser';

const src = new URL('../mpl/amd/src/', import.meta.url).pathname;
const build = new URL('../mpl/amd/build/', import.meta.url).pathname;

const external = (id) => /^(core|core_[a-z]+|mod_mpl|editor_tiny|jquery)(\/|$)/.test(id);

export default readdirSync(src).filter((f) => f.endsWith('.js')).map((file) => ({
    input: src + file,
    external,
    plugins: [nodeResolve({modulePaths: [new URL('./node_modules', import.meta.url).pathname]})],
    output: {
        file: build + file.replace(/\.js$/, '.min.js'),
        format: 'amd',
        interop: 'auto',
        sourcemap: true,
        plugins: [terser({format: {comments: false}})],
    },
}));
