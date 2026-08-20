import type { RollupOutput } from 'rollup';
import { build } from 'vite';
import { expect, test } from 'vitest';

/**
 * A smoke test for the production library build.
 *
 * It runs the real `vite.config.ts` through Vite's programmatic `build()` with
 * `write: false`, so nothing touches `dist/`, and asserts the build resolves
 * without throwing and emits the three subpath entrypoints the `exports` map in
 * `package.json` points at. A broken entry, a bad import, or an unresolved
 * dependency fails here rather than at publish time.
 */
test('the production library build resolves cleanly', async () => {
	const result = await build({
		configFile: 'vite.config.ts',
		logLevel: 'silent',
		build: { write: false },
	});

	const outputs = (Array.isArray(result) ? result : [result]) as RollupOutput[];
	const emitted = outputs.flatMap((output) =>
		output.output.map((chunk) => chunk.fileName),
	);

	expect(emitted).toContain('core/index.js');
	expect(emitted).toContain('react/index.js');
	expect(emitted).toContain('vue/index.js');
}, 60_000);
