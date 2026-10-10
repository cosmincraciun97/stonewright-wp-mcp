import { defineConfig } from 'vitest/config';

export default defineConfig({
	test: {
		globals: false,
		environment: 'node',
		globalSetup: ['./tests/helpers/oauth-fixture-global-setup.ts'],
		include: ['tests/**/*.test.ts'],
		fileParallelism: false,
		coverage: {
			provider: 'v8',
			include: ['src/**/*.ts'],
		},
	},
});
