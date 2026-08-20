import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { defineConfig } from 'vite';

const packageRoot = dirname(fileURLToPath(import.meta.url));

/**
 * Production library build for the bookings JS package.
 *
 * Three independent entrypoints — the framework-agnostic `core`, the `react`
 * bindings, and the `vue` bindings — are bundled to ESM under `dist/`, matching
 * the subpath `exports` map in `package.json`. React and Vue (and their runtime
 * subpaths) stay external so a consumer's own copy is used rather than a second
 * one shipped inside the widget bundle.
 *
 * TypeScript declarations are emitted separately by `tsc --emitDeclarationOnly`
 * in the `build` script; this config only produces the JavaScript.
 */
export default defineConfig({
	esbuild: {
		jsx: 'automatic',
		jsxImportSource: 'react',
	},
	build: {
		outDir: 'dist',
		emptyOutDir: true,
		target: 'es2020',
		minify: false,
		sourcemap: true,
		lib: {
			entry: {
				'core/index': resolve(packageRoot, 'resources/js/core/index.ts'),
				'react/index': resolve(packageRoot, 'resources/js/react/index.ts'),
				'vue/index': resolve(packageRoot, 'resources/js/vue/index.ts'),
			},
			formats: ['es'],
		},
		rollupOptions: {
			external: [/^react(\/.*)?$/, /^react-dom(\/.*)?$/, /^vue(\/.*)?$/],
			output: {
				entryFileNames: '[name].js',
				chunkFileNames: 'shared/[name]-[hash].js',
			},
		},
	},
});
