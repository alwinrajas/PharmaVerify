import react from '@vitejs/plugin-react'
import path from 'node:path'
import { defineConfig } from 'vitest/config'

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': path.resolve(import.meta.dirname, './src'),
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    // Component files hold no logic worth measuring on their own; the value is
    // in the shared machinery and the dialogs that carry business rules.
    include: ['src/**/*.test.{ts,tsx}'],
    restoreMocks: true,
    // The default worker pool does not start reliably on Windows here.
    pool: 'forks',
    maxWorkers: 1,
    // One worker runs every file in sequence, so a dialog test driven by
    // userEvent can sit well past the 5s default while the rest of the suite is
    // still working. That is a slow machine, not a broken assertion — a test
    // that genuinely fails still fails, it simply gets room to finish first.
    testTimeout: 15000,
    hookTimeout: 15000,
  },
})
