/* ===========================================================================
   PharmaVerify - clear business data, keep the people who use it  (MySQL)
   ---------------------------------------------------------------------------
   The MySQL companion to reset-test-data-sqlserver.sql, for the development
   database the application is currently configured against.

   Same rules: nothing is dropped, nothing is altered, and users, roles,
   permissions and configuration survive untouched.

   REPORT FIRST. Run the SELECT block on its own to see the figures, then run
   the transaction block. Safe to run repeatedly.

       mysql -u root -p pharmaverify < reset-test-data-mysql.sql

   See docs/18-BACKUP-AND-RECOVERY.md before running this anywhere that matters.
   =========================================================================== */

/* ---------------------------------------------------------------------------
   1. Report - what would be preserved, and what would go.
   ------------------------------------------------------------------------ */

SELECT 'PRESERVED' AS disposition, 'users' AS table_name, COUNT(*) AS rows_now, 'The people who sign in' AS reason FROM users
UNION ALL SELECT 'PRESERVED', 'roles', COUNT(*), 'Role definitions' FROM roles
UNION ALL SELECT 'PRESERVED', 'permissions', COUNT(*), 'Permission definitions' FROM permissions
UNION ALL SELECT 'PRESERVED', 'model_has_roles', COUNT(*), 'Which user holds which role' FROM model_has_roles
UNION ALL SELECT 'PRESERVED', 'model_has_permissions', COUNT(*), 'Permissions granted directly to a user' FROM model_has_permissions
UNION ALL SELECT 'PRESERVED', 'role_has_permissions', COUNT(*), 'Which permissions each role carries' FROM role_has_permissions
UNION ALL SELECT 'PRESERVED', 'app_settings', COUNT(*), 'Application configuration, not business data' FROM app_settings
UNION ALL SELECT 'PRESERVED', 'migrations', COUNT(*), 'Schema ledger' FROM migrations

UNION ALL SELECT 'DELETE', 'activity_log', COUNT(*), 'Audit trail of user actions' FROM activity_log
UNION ALL SELECT 'DELETE', 'final_outputs', COUNT(*), 'Generated output files' FROM final_outputs
UNION ALL SELECT 'DELETE', 'stock_adjustments', COUNT(*), 'Posted adjustments' FROM stock_adjustments
UNION ALL SELECT 'DELETE', 'audit_lines', COUNT(*), 'Counted lines' FROM audit_lines
UNION ALL SELECT 'DELETE', 'hht_submissions', COUNT(*), 'Handheld submission ledger' FROM hht_submissions
UNION ALL SELECT 'DELETE', 'audits', COUNT(*), 'Stock audits' FROM audits
UNION ALL SELECT 'DELETE', 'stock_takes', COUNT(*), 'Stock take lines' FROM stock_takes
UNION ALL SELECT 'DELETE', 'stock_take_sessions', COUNT(*), 'Stock take cycles' FROM stock_take_sessions
UNION ALL SELECT 'DELETE', 'stock_import_errors', COUNT(*), 'Rejected import rows' FROM stock_import_errors
UNION ALL SELECT 'DELETE', 'stock_imports', COUNT(*), 'Import history' FROM stock_imports
UNION ALL SELECT 'DELETE', 'item_stocks', COUNT(*), 'ERP stock snapshot' FROM item_stocks
UNION ALL SELECT 'DELETE', 'items', COUNT(*), 'Item master' FROM items
UNION ALL SELECT 'DELETE', 'devices', COUNT(*), 'Handheld devices' FROM devices
UNION ALL SELECT 'DELETE', 'shop_user', COUNT(*), 'Shop scoping - shops are going' FROM shop_user
UNION ALL SELECT 'DELETE', 'shops', COUNT(*), 'Shops' FROM shops
ORDER BY disposition DESC, table_name;

/* ---------------------------------------------------------------------------
   2. Execute.

   One transaction, children before parents. Foreign key checks stay ON: the
   order below satisfies every constraint on its own, and leaving the checks in
   place means a mistake in that order fails loudly rather than leaving orphans
   behind.
   ------------------------------------------------------------------------ */

START TRANSACTION;

DELETE FROM activity_log;
DELETE FROM final_outputs;
DELETE FROM stock_adjustments;
DELETE FROM audit_lines;
DELETE FROM hht_submissions;
DELETE FROM audits;
DELETE FROM stock_takes;
DELETE FROM stock_take_sessions;
DELETE FROM stock_import_errors;
DELETE FROM stock_imports;
DELETE FROM item_stocks;
DELETE FROM items;
DELETE FROM devices;
DELETE FROM shop_user;
DELETE FROM shops;

/* Transient framework tables. Harmless to clear, and it gives a tidy start.
   `personal_access_tokens` is deliberately absent: clearing it signs out every
   browser and handheld immediately. Uncomment only if that is wanted. */
DELETE FROM cache;
DELETE FROM cache_locks;
DELETE FROM sessions;
DELETE FROM jobs;
DELETE FROM job_batches;
DELETE FROM failed_jobs;
DELETE FROM password_reset_tokens;
-- DELETE FROM personal_access_tokens;

COMMIT;

/* ---------------------------------------------------------------------------
   3. Reseed identities so the next shop is id 1 again.

   Cosmetic, and outside the transaction because ALTER TABLE cannot take part in
   one on MySQL - it commits implicitly. Run it after the commit above, never
   instead of it.
   ------------------------------------------------------------------------ */

ALTER TABLE activity_log        AUTO_INCREMENT = 1;
ALTER TABLE final_outputs       AUTO_INCREMENT = 1;
ALTER TABLE stock_adjustments   AUTO_INCREMENT = 1;
ALTER TABLE audit_lines         AUTO_INCREMENT = 1;
ALTER TABLE hht_submissions     AUTO_INCREMENT = 1;
ALTER TABLE audits              AUTO_INCREMENT = 1;
ALTER TABLE stock_takes         AUTO_INCREMENT = 1;
ALTER TABLE stock_take_sessions AUTO_INCREMENT = 1;
ALTER TABLE stock_import_errors AUTO_INCREMENT = 1;
ALTER TABLE stock_imports       AUTO_INCREMENT = 1;
ALTER TABLE item_stocks         AUTO_INCREMENT = 1;
ALTER TABLE items               AUTO_INCREMENT = 1;
ALTER TABLE devices             AUTO_INCREMENT = 1;
ALTER TABLE shop_user           AUTO_INCREMENT = 1;
ALTER TABLE shops               AUTO_INCREMENT = 1;

/* ---------------------------------------------------------------------------
   4. Confirm what survived.
   ------------------------------------------------------------------------ */

SELECT 'users' AS preserved, COUNT(*) AS rows_now FROM users
UNION ALL SELECT 'roles', COUNT(*) FROM roles
UNION ALL SELECT 'permissions', COUNT(*) FROM permissions
UNION ALL SELECT 'model_has_roles', COUNT(*) FROM model_has_roles
UNION ALL SELECT 'role_has_permissions', COUNT(*) FROM role_has_permissions
UNION ALL SELECT 'app_settings', COUNT(*) FROM app_settings
UNION ALL SELECT 'migrations', COUNT(*) FROM migrations;
