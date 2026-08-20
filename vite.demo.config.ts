import { defineConfig } from 'vite';

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
		// which sits outside the `demo/` root, so the dev server has to be allowed
		// to read past it.
		fs: {
			strict: false,
		},
	},
});
