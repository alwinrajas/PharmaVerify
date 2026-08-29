/* ===========================================================================
   PharmaVerify - clear business data, keep the people who use it
   ---------------------------------------------------------------------------
   Empties every table that holds shop, stock, count or import data, and leaves
   users, roles, permissions and application configuration exactly as they are.

   Nothing is dropped and nothing is altered. Tables, columns, indexes,
   constraints and the migrations ledger are all untouched, so the application
   boots against the result without re-migrating.

   REPORT FIRST. The script runs in report mode by default: it prints what it
   would preserve and what it would delete, and writes nothing. Set @Execute = 1
   only once the figures look right.

       sqlcmd -S <host> -d PharmaVerify -i reset-test-data-sqlserver.sql

   Safe to run repeatedly. Every statement is a DELETE against a table that may
   already be empty, and every table reference is guarded, so a database that
   has not yet had the latest migrations applied is handled rather than erroring.

   See docs/18-BACKUP-AND-RECOVERY.md before running this anywhere that matters.
   =========================================================================== */

SET NOCOUNT ON;
SET XACT_ABORT ON;

/* ---------------------------------------------------------------------------
   Switches
   ---------------------------------------------------------------------------
   @Execute            0 = report only (default), 1 = delete.
   @ClearRuntime       Cache, sessions and queue tables. Transient by nature;
                       clearing them is harmless and gives a tidier start.
   @ClearApiTokens     Personal access tokens. OFF by default because clearing
                       them signs out every browser and handheld immediately,
                       including whoever is running this. Users and their roles
                       survive either way, so signing back in is all it costs.
   @ResetIdentities    Reseed identity columns on the cleared tables so the next
                       shop is id 1 again. Cosmetic, but it makes a test run
                       readable.
--------------------------------------------------------------------------- */

DECLARE @Execute         bit = 0;
DECLARE @ClearRuntime    bit = 1;
DECLARE @ClearApiTokens  bit = 0;
DECLARE @ResetIdentities bit = 1;

/* ---------------------------------------------------------------------------
   The plan, as data.

   `phase` fixes the delete order. It runs children before parents so no
   constraint is ever relied on to cascade — several of these relationships do
   cascade, but an explicit order means the row counts reported below are the
   rows this script actually removed, not a mixture of that and whatever the
   engine tidied up behind it.
--------------------------------------------------------------------------- */

DECLARE @Plan TABLE (
    phase        int,
    table_name   sysname,
    reason       nvarchar(200),
    reseed       bit
);

INSERT INTO @Plan (phase, table_name, reason, reseed) VALUES
    -- Counted work, deepest first.
    ( 1, 'activity_log',        'Audit trail of user actions - transactional',            1),
    ( 2, 'final_outputs',       'Generated output files and their OneDrive state',        1),
    ( 3, 'stock_adjustments',   'Posted adjustments',                                     1),
    ( 4, 'audit_lines',         'Counted lines - child of audits',                        1),
    ( 5, 'hht_submissions',     'Handheld submission ledger',                             1),
    ( 6, 'audits',              'Stock audits',                                           1),
    ( 7, 'stock_takes',         'Stock take lines',                                       1),
    ( 8, 'stock_take_sessions', 'Stock take cycles',                                      1),
    -- Imports.
    ( 9, 'stock_import_errors', 'Rejected import rows - child of stock_imports',          1),
    (10, 'stock_imports',       'Import history',                                         1),
    -- Master data.
    (11, 'item_stocks',         'ERP stock snapshot',                                     1),
    (12, 'items',               'Item master',                                            1),
    (13, 'devices',             'Handheld devices - child of shops',                      1),
    (14, 'shop_user',           'Shop scoping for Shop Users - shops are going',          1),
    (15, 'shops',               'Shops',                                                  1);

IF @ClearRuntime = 1
    INSERT INTO @Plan (phase, table_name, reason, reseed) VALUES
        (20, 'cache',                 'Framework cache - transient',                      0),
        (21, 'cache_locks',           'Framework cache locks - transient',                0),
        (22, 'sessions',              'Web sessions - transient',                         0),
        (23, 'jobs',                  'Queued jobs - transient',                          1),
        (24, 'job_batches',           'Queue batches - transient',                        0),
        (25, 'failed_jobs',           'Failed queue jobs - transient',                    1),
        (26, 'password_reset_tokens', 'Expiring reset tokens - transient',                0);

IF @ClearApiTokens = 1
    INSERT INTO @Plan (phase, table_name, reason, reseed) VALUES
        (30, 'personal_access_tokens', 'API tokens - signs everyone out',                 1);

/* ---------------------------------------------------------------------------
   What survives, and why.
--------------------------------------------------------------------------- */

DECLARE @Preserved TABLE (table_name sysname, reason nvarchar(200));

INSERT INTO @Preserved (table_name, reason) VALUES
    ('users',                  'The people who sign in - explicitly preserved'),
    ('roles',                  'Role definitions'),
    ('permissions',            'Permission definitions'),
    ('model_has_roles',        'Which user holds which role'),
    ('model_has_permissions',  'Permissions granted directly to a user'),
    ('role_has_permissions',   'Which permissions each role carries'),
    ('app_settings',           'Application configuration, not business data'),
    ('migrations',             'Schema ledger - removing rows would re-run migrations');

IF @ClearApiTokens = 0
    INSERT INTO @Preserved (table_name, reason) VALUES
        ('personal_access_tokens', 'Kept so open sessions are not signed out (@ClearApiTokens = 0)');

/* ===========================================================================
   1. Report
   =========================================================================== */

PRINT '';
PRINT '===========================================================================';
PRINT ' PharmaVerify - clear business data      database: ' + DB_NAME();
PRINT ' mode: ' + CASE WHEN @Execute = 1 THEN 'EXECUTE - rows will be deleted' ELSE 'REPORT ONLY - nothing will be written' END;
PRINT '===========================================================================';
PRINT '';

PRINT '--- PRESERVED -------------------------------------------------------------';

DECLARE @name sysname, @reason nvarchar(200), @rows bigint, @sql nvarchar(max);

DECLARE preserved_cursor CURSOR LOCAL FAST_FORWARD FOR
    SELECT table_name, reason FROM @Preserved ORDER BY table_name;

OPEN preserved_cursor;
FETCH NEXT FROM preserved_cursor INTO @name, @reason;

WHILE @@FETCH_STATUS = 0
BEGIN
    IF OBJECT_ID(@name, 'U') IS NULL
        PRINT '  ' + LEFT(@name + REPLICATE(' ', 24), 24) + '        (table not present)';
    ELSE
    BEGIN
        SET @sql = N'SELECT @c = COUNT(*) FROM ' + QUOTENAME(@name) + N';';
        EXEC sp_executesql @sql, N'@c bigint OUTPUT', @c = @rows OUTPUT;
        PRINT '  ' + LEFT(@name + REPLICATE(' ', 24), 24) + RIGHT(REPLICATE(' ', 8) + CAST(@rows AS varchar(20)), 8) + '  ' + @reason;
    END

    FETCH NEXT FROM preserved_cursor INTO @name, @reason;
END

CLOSE preserved_cursor;
DEALLOCATE preserved_cursor;

PRINT '';
PRINT '--- TO BE DELETED ---------------------------------------------------------';

DECLARE @phase int, @reseed bit, @total bigint = 0;

DECLARE plan_cursor CURSOR LOCAL FAST_FORWARD FOR
    SELECT phase, table_name, reason, reseed FROM @Plan ORDER BY phase;

OPEN plan_cursor;
FETCH NEXT FROM plan_cursor INTO @phase, @name, @reason, @reseed;

WHILE @@FETCH_STATUS = 0
BEGIN
    IF OBJECT_ID(@name, 'U') IS NULL
        PRINT '  ' + LEFT(@name + REPLICATE(' ', 24), 24) + '        (table not present - skipped)';
    ELSE
    BEGIN
        SET @sql = N'SELECT @c = COUNT(*) FROM ' + QUOTENAME(@name) + N';';
        EXEC sp_executesql @sql, N'@c bigint OUTPUT', @c = @rows OUTPUT;
        SET @total = @total + @rows;
        PRINT '  ' + LEFT(@name + REPLICATE(' ', 24), 24) + RIGHT(REPLICATE(' ', 8) + CAST(@rows AS varchar(20)), 8) + '  ' + @reason;
    END

    FETCH NEXT FROM plan_cursor INTO @phase, @name, @reason, @reseed;
END

CLOSE plan_cursor;
DEALLOCATE plan_cursor;

PRINT '';
PRINT '  Rows that would be deleted in total: ' + CAST(@total AS varchar(20));
PRINT '';

IF @Execute = 0
BEGIN
    PRINT '===========================================================================';
    PRINT ' REPORT ONLY. Nothing has been written.';
    PRINT ' Set @Execute = 1 at the top of this script to carry it out.';
    PRINT '===========================================================================';
    RETURN;
END

/* ===========================================================================
   2. Execute
   ---------------------------------------------------------------------------
   One transaction. Any failure rolls the whole thing back, so the database is
   never left half emptied.
   =========================================================================== */

PRINT '--- DELETING --------------------------------------------------------------';

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @deleted bigint = 0;

    DECLARE delete_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT phase, table_name, reseed FROM @Plan ORDER BY phase;

    OPEN delete_cursor;
    FETCH NEXT FROM delete_cursor INTO @phase, @name, @reseed;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        IF OBJECT_ID(@name, 'U') IS NOT NULL
        BEGIN
            /* DELETE rather than TRUNCATE: TRUNCATE is refused on a table
               another table references, which is most of these, and it cannot
               report how many rows it removed. */
            SET @sql = N'DELETE FROM ' + QUOTENAME(@name) + N';';
            EXEC sp_executesql @sql;
            SET @rows = @@ROWCOUNT;
            SET @deleted = @deleted + @rows;

            PRINT '  ' + LEFT(@name + REPLICATE(' ', 24), 24) + RIGHT(REPLICATE(' ', 8) + CAST(@rows AS varchar(20)), 8) + '  deleted';

            /* Reseed so the next row is id 1. RESEED 0 with no rows present is
               the documented way to do that; on a table that still holds rows
               it would be wrong, so it only runs once the table is empty. */
            IF @ResetIdentities = 1
               AND @reseed = 1
               AND EXISTS (SELECT 1 FROM sys.identity_columns WHERE object_id = OBJECT_ID(@name))
            BEGIN
                SET @sql = N'IF NOT EXISTS (SELECT 1 FROM ' + QUOTENAME(@name) + N') DBCC CHECKIDENT (''' + @name + N''', RESEED, 0) WITH NO_INFOMSGS;';
                EXEC sp_executesql @sql;
            END
        END

        FETCH NEXT FROM delete_cursor INTO @phase, @name, @reseed;
    END

    CLOSE delete_cursor;
    DEALLOCATE delete_cursor;

    /* -----------------------------------------------------------------------
       A last look before committing. If the people who sign in have gone, the
       script has done something it was never meant to, and the transaction is
       abandoned rather than committed.
    ----------------------------------------------------------------------- */

    DECLARE @users int, @roles int, @perms int, @assignments int;

    SELECT @users = COUNT(*) FROM users;
    SELECT @roles = COUNT(*) FROM roles;
    SELECT @perms = COUNT(*) FROM permissions;
    SELECT @assignments = COUNT(*) FROM model_has_roles;

    IF @users = 0 OR @roles = 0 OR @perms = 0 OR @assignments = 0
        THROW 50001, 'Users, roles, permissions or role assignments were emptied. Rolling back - this script must never remove them.', 1;

    IF OBJECT_ID('migrations', 'U') IS NOT NULL AND NOT EXISTS (SELECT 1 FROM migrations)
        THROW 50002, 'The migrations ledger was emptied. Rolling back - the schema would be re-applied on the next deploy.', 1;

    COMMIT TRANSACTION;

    PRINT '';
    PRINT '===========================================================================';
    PRINT ' Done. ' + CAST(@deleted AS varchar(20)) + ' row(s) deleted, committed.';
    PRINT '';
    PRINT ' Preserved: users ' + CAST(@users AS varchar(10))
        + ', roles ' + CAST(@roles AS varchar(10))
        + ', permissions ' + CAST(@perms AS varchar(10))
        + ', role assignments ' + CAST(@assignments AS varchar(10)) + '.';
    PRINT '';
    PRINT ' Next steps for a usable test database:';
    PRINT '   1. Create the shops, and set the AX location (INVENTLOCATIONID) on each.';
    PRINT '   2. Re-assign any Shop Users to their shops - shop_user was cleared with the shops.';
    PRINT '   3. Register the handheld devices.';
    PRINT '   4. Import the Item Master, then the Stock Report.';
    PRINT '===========================================================================';
END TRY
BEGIN CATCH
    IF CURSOR_STATUS('local', 'delete_cursor') >= 0
    BEGIN
        CLOSE delete_cursor;
        DEALLOCATE delete_cursor;
    END

    IF XACT_STATE() <> 0
        ROLLBACK TRANSACTION;

    PRINT '';
    PRINT '===========================================================================';
    PRINT ' FAILED - rolled back. The database is exactly as it was.';
    PRINT '===========================================================================';

    THROW;
END CATCH
