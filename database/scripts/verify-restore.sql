/* ===========================================================================
   PharmaVerify - post-restore verification
   ---------------------------------------------------------------------------
   Run against the RESTORED database. Read-only: it writes nothing.

   A restore that reports success only proves SQL Server could read the file.
   These checks prove PharmaVerify's own rules still hold in the restored data.

   Every row of the final result set must read PASS. Investigate any FAIL
   before letting users back in.
   =========================================================================== */

USE PharmaVerify;
GO

SET NOCOUNT ON;

DECLARE @Result TABLE (
    seq     int IDENTITY(1,1),
    area    nvarchar(30),
    check_  nvarchar(80),
    status  nvarchar(6),
    detail  nvarchar(200)
);

/* --------------------------------------------------------------- 1. Schema */

DECLARE @Expected TABLE (name sysname);
INSERT INTO @Expected (name) VALUES
    ('activity_log'),('app_settings'),('audit_lines'),('audits'),('devices'),
    ('final_outputs'),('hht_submissions'),('item_stocks'),('items'),
    ('migrations'),('model_has_permissions'),('model_has_roles'),
    ('permissions'),('personal_access_tokens'),('role_has_permissions'),
    ('roles'),('shop_user'),('shops'),('stock_adjustments'),
    ('stock_import_errors'),('stock_imports'),('stock_takes'),('users');

INSERT INTO @Result (area, check_, status, detail)
SELECT 'Schema', 'All core tables present',
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END,
       CASE WHEN COUNT(*) = 0 THEN 'All 23 core tables restored'
            ELSE 'Missing: ' + STRING_AGG(e.name, ', ') END
FROM   @Expected e
WHERE  NOT EXISTS (SELECT 1 FROM sys.tables t WHERE t.name = e.name);

/* ------------------------------------------------------ 2. Reference data */

INSERT INTO @Result (area, check_, status, detail)
SELECT 'Reference', 'Roles seeded',
       CASE WHEN COUNT(*) >= 3 THEN 'PASS' ELSE 'FAIL' END,
       CAST(COUNT(*) AS nvarchar(10)) + ' roles (expected at least 3)'
FROM roles;

INSERT INTO @Result (area, check_, status, detail)
SELECT 'Reference', 'Permissions seeded',
       CASE WHEN COUNT(*) > 0 THEN 'PASS' ELSE 'FAIL' END,
       CAST(COUNT(*) AS nvarchar(10)) + ' permissions'
FROM permissions;

INSERT INTO @Result (area, check_, status, detail)
SELECT 'Reference', 'At least one active administrator',
       CASE WHEN COUNT(*) > 0 THEN 'PASS' ELSE 'FAIL' END,
       CAST(COUNT(*) AS nvarchar(10)) + ' active administrator account(s)'
FROM   users u
JOIN   model_has_roles mhr ON mhr.model_id = u.id
JOIN   roles r ON r.id = mhr.role_id AND r.name = 'Administrator'
WHERE  u.status = 'active';

INSERT INTO @Result (area, check_, status, detail)
SELECT 'Reference', 'Shops present',
       CASE WHEN COUNT(*) > 0 THEN 'PASS' ELSE 'FAIL' END,
       CAST(COUNT(*) AS nvarchar(10)) + ' shops'
FROM shops;

/* -------------------------------------------------- 3. Referential integrity
   The restore is atomic, so orphans here mean the backup was taken from an
   inconsistent source, or the wrong log chain was applied. */

INSERT INTO @Result (area, check_, status, detail)
SELECT 'Integrity', 'Every audit line belongs to an audit',
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END,
       CAST(COUNT(*) AS nvarchar(10)) + ' orphaned audit_lines'
FROM   audit_lines al
WHERE  NOT EXISTS (SELECT 1 FROM audits a WHERE a.id = al.audit_id);

INSERT INTO @Result (area, check_, status, detail)
SELECT 'Integrity', 'Every adjustment points at its audit line',
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END,
       CAST(COUNT(*) AS nvarchar(10)) + ' orphaned stock_adjustments'
FROM   stock_adjustments sa
WHERE  sa.audit_line_id IS NOT NULL
  AND  NOT EXISTS (SELECT 1 FROM audit_lines al WHERE al.id = sa.audit_line_id);

INSERT INTO @Result (area, check_, status, detail)
SELECT 'Integrity', 'Every audit belongs to a shop and a device',
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END,
       CAST(COUNT(*) AS nvarchar(10)) + ' audits with a missing shop or device'
FROM   audits a
WHERE  NOT EXISTS (SELECT 1 FROM shops s   WHERE s.id = a.shop_id)
   OR  NOT EXISTS (SELECT 1 FROM devices d WHERE d.id = a.device_id);

/* ------------------------------------------------- 4. Business invariants */

INSERT INTO @Result (area, check_, status, detail)
SELECT 'Business', 'variance_qty = physical_qty - system_qty',
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END,
       CAST(COUNT(*) AS nvarchar(10)) + ' audit_lines with an inconsistent variance'
FROM   audit_lines
WHERE  variance_qty <> (physical_qty - system_qty);

INSERT INTO @Result (area, check_, status, detail)
SELECT 'Business', 'Stock identity unique per shop, product and batch',
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END,
       CAST(COUNT(*) AS nvarchar(10)) + ' duplicate stock identities'
FROM   (SELECT shop_id, product_code, batch
        FROM   item_stocks
        GROUP  BY shop_id, product_code, batch
        HAVING COUNT(*) > 1) d;

INSERT INTO @Result (area, check_, status, detail)
SELECT 'Business', 'Submission identity unique per shop, device and audit',
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END,
       CAST(COUNT(*) AS nvarchar(10)) + ' duplicate audit identities'
FROM   (SELECT shop_id, device_id, audit_number
        FROM   audits
        GROUP  BY shop_id, device_id, audit_number
        HAVING COUNT(*) > 1) d;

/* ----------------------------------------------------- 5. Recovery point
   How much was lost. Compare these against what the business expected to be
   there at the moment of failure. */

INSERT INTO @Result (area, check_, status, detail)
SELECT 'Recovery point', 'Most recent recorded activity', 'INFO',
       ISNULL(CONVERT(nvarchar(30), MAX(created_at), 120), 'no activity recorded')
FROM activity_log;

INSERT INTO @Result (area, check_, status, detail)
SELECT 'Recovery point', 'Most recent audit submission', 'INFO',
       ISNULL(CONVERT(nvarchar(30), MAX(submitted_at), 120), 'none')
FROM audits;

INSERT INTO @Result (area, check_, status, detail)
SELECT 'Recovery point', 'Most recent adjustment', 'INFO',
       ISNULL(CONVERT(nvarchar(30), MAX(adjusted_at), 120), 'none')
FROM stock_adjustments;

/* ----------------------------------------------------------- 6. Files
   Rows come from the database backup, files from the file backup. If the two
   were taken at different points these counts will disagree with what is on
   disk. Only an actual download proves they agree - see docs/18 section 5.3. */

INSERT INTO @Result (area, check_, status, detail)
SELECT 'Files', 'Final outputs expecting a file on disk', 'INFO',
       CAST(COUNT(*) AS nvarchar(10)) + ' file(s) under storage/app/private/final-output'
FROM final_outputs WHERE file_path IS NOT NULL;

INSERT INTO @Result (area, check_, status, detail)
SELECT 'Files', 'Imports expecting a source file on disk', 'INFO',
       CAST(COUNT(*) AS nvarchar(10)) + ' file(s) under storage/app/private/imports'
FROM stock_imports WHERE stored_path IS NOT NULL;

/* ------------------------------------------------------------- 7. Volumes */

INSERT INTO @Result (area, check_, status, detail)
SELECT 'Volume', 'Row counts', 'INFO',
       'shops='        + CAST((SELECT COUNT(*) FROM shops)             AS nvarchar(10)) +
       ' items='       + CAST((SELECT COUNT(*) FROM items)             AS nvarchar(10)) +
       ' stock='       + CAST((SELECT COUNT(*) FROM item_stocks)       AS nvarchar(10)) +
       ' audits='      + CAST((SELECT COUNT(*) FROM audits)            AS nvarchar(10)) +
       ' lines='       + CAST((SELECT COUNT(*) FROM audit_lines)       AS nvarchar(10)) +
       ' adjustments=' + CAST((SELECT COUNT(*) FROM stock_adjustments) AS nvarchar(10));

/* -------------------------------------------------------------- Results */

SELECT area, check_ AS [check], status, detail FROM @Result ORDER BY seq;

IF EXISTS (SELECT 1 FROM @Result WHERE status = 'FAIL')
    PRINT '*** ONE OR MORE CHECKS FAILED - do not return the system to service. ***';
ELSE
    PRINT 'All checks passed. Now run php artisan migrate:status and the application checks in docs/18 section 5.3.';
GO
