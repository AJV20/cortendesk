import { createRequire } from 'node:module';
import { defineConfig } from 'vitest/config';

const require = createRequire(import.meta.url);

export default defineConfig({
  resolve: {
    alias: {
      'libsodium-wrappers': require.resolve('libsodium-wrappers'),
    },
  },
  test: {
    environment: 'node',
  },
});
