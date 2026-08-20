import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { defineConfig } from 'vite';

const packageRoot = dirname(fileURLToPath(import.meta.url));

/**
 * Dev-only config for the browser demo under `demo/`.
 *
 * The demo imports the compiled widgets from `dist/`, so `npm run demo` builds
 * first. This config is never used to build anything the package ships.
 */
export default defineConfig({
	root: 'demo',
	esbuild: {
		jsx: 'automatic',
		jsxImportSource: 'react',
	},
	server: {
		open: '/index.html',
		// The demo imports the built widgets from the sibling `dist/` directory,
		// which sits outside the `demo/` root. Rather than lift the strict
		// filesystem guard wholesale, allow only the two directories the demo
		// actually reads from.
		fs: {
			allow: [resolve(packageRoot, 'demo'), resolve(packageRoot, 'dist')],
		},
	},
});
