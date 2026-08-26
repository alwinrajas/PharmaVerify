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
  },
})
