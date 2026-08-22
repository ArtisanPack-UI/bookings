import { defineConfig } from 'vitest/config';

export default defineConfig({
	esbuild: {
		jsx: 'automatic',
		jsxImportSource: 'react',
	},
	test: {
		// The core and the flow controllers run under node; the React and Vue
		// component tests opt into jsdom with a `@vitest-environment jsdom`
		// docblock of their own, so only the tests that need a DOM pay for one.
		environment: 'node',
		include: ['resources/js/**/*.test.ts', 'resources/js/**/*.test.tsx'],
	},
});
