/* ===========================================================================
   PharmaVerify - SQL Server restore
   ---------------------------------------------------------------------------
   Read docs/18-BACKUP-AND-RECOVERY.md section 4 before running any of this.

   ORDER OF WORK
     1. php artisan down                     (stop writes)
     2. this script                          (restore the database)
     3. robocopy the final-output files back (restore the files)
     4. verify-restore.sql                   (check the data)
     5. php artisan migrate:status           (check the schema)
     6. php artisan up                       (back into service)

   WARNING: section 3 overwrites the target database. Confirm which instance
   you are connected to before running it.
   =========================================================================== */

USE master;
GO

/* ---------------------------------------------------------------------------
   1. What is available to restore from?
   --------------------------------------------------------------------------- */

SELECT TOP (30)
       CASE bs.type WHEN 'D' THEN 'Full'
                    WHEN 'I' THEN 'Differential'
                    WHEN 'L' THEN 'Log' END AS backup_type,
       bs.backup_start_date,
       bs.first_lsn, bs.last_lsn,
       bmf.physical_device_name
FROM   msdb.dbo.backupset bs
JOIN   msdb.dbo.backupmediafamily bmf ON bmf.media_set_id = bs.media_set_id
WHERE  bs.database_name = N'PharmaVerify'
ORDER  BY bs.backup_start_date DESC;
GO

/* Confirm the file is readable and lists the expected database. */
RESTORE HEADERONLY  FROM DISK = N'D:\Backups\PharmaVerify\PharmaVerify_FULL.bak';
RESTORE FILELISTONLY FROM DISK = N'D:\Backups\PharmaVerify\PharmaVerify_FULL.bak';
GO

/* ---------------------------------------------------------------------------
   2. Close existing connections
   ---------------------------------------------------------------------------
   A restore cannot proceed while sessions hold the database. Skip this when
   restoring under a NEW name - there will be no connections to drop.
   --------------------------------------------------------------------------- */

-- ALTER DATABASE PharmaVerify SET SINGLE_USER WITH ROLLBACK IMMEDIATE;
GO

/* ---------------------------------------------------------------------------
   3. Restore
   ---------------------------------------------------------------------------
   Full, then the latest differential, then every log backup in order.
   Every step except the last uses NORECOVERY.

   MOVE is needed only when the data file paths differ from the source server,
   which they usually do on a drill instance.
   --------------------------------------------------------------------------- */

RESTORE DATABASE PharmaVerify
    FROM DISK = N'D:\Backups\PharmaVerify\PharmaVerify_FULL.bak'
    WITH NORECOVERY, REPLACE, STATS = 10;
    --, MOVE N'PharmaVerify'     TO N'D:\SQLData\PharmaVerify.mdf'
    --, MOVE N'PharmaVerify_log' TO N'D:\SQLLog\PharmaVerify_log.ldf';
GO

RESTORE DATABASE PharmaVerify
    FROM DISK = N'D:\Backups\PharmaVerify\PharmaVerify_DIFF.bak'
    WITH NORECOVERY, STATS = 10;
GO

/* Repeat for each log backup taken after that differential, oldest first. */
RESTORE LOG PharmaVerify
    FROM DISK = N'D:\Backups\PharmaVerify\PharmaVerify_LOG_0001.trn'
    WITH NORECOVERY;
GO

/* ---------------------------------------------------------------------------
   4. The final statement - brings the database online
   ---------------------------------------------------------------------------
   STOPAT recovers to a moment in time. Use it when the loss has a known time,
   such as a bad import or a mistaken bulk adjustment. Drop the STOPAT clause
   to recover everything the backups contain.

   Nothing can be applied after RECOVERY. If you are unsure whether more logs
   are needed, keep using NORECOVERY.
   --------------------------------------------------------------------------- */

RESTORE LOG PharmaVerify
    FROM DISK = N'D:\Backups\PharmaVerify\PharmaVerify_LOG_LAST.trn'
    WITH RECOVERY,
         STOPAT = N'2026-08-26T12:25:00';
GO

/* ---------------------------------------------------------------------------
   5. Back to normal
   --------------------------------------------------------------------------- */

-- ALTER DATABASE PharmaVerify SET MULTI_USER;
GO

/* ---------------------------------------------------------------------------
   6. Re-map the application login
   ---------------------------------------------------------------------------
   A restored database carries its own users. On a different server the login
   SID will not match, leaving an orphaned user that cannot connect - the
   application then fails to authenticate against a database that restored
   perfectly. This is the single most common post-restore surprise.
   --------------------------------------------------------------------------- */

USE PharmaVerify;
GO

SELECT dp.name AS orphaned_user, dp.type_desc
FROM   sys.database_principals dp
LEFT   JOIN sys.server_principals sp ON sp.sid = dp.sid
WHERE  dp.type IN ('S','U','G')
  AND  dp.authentication_type_desc <> 'NONE'
  AND  sp.sid IS NULL
  AND  dp.name NOT IN ('dbo','guest','INFORMATION_SCHEMA','sys');
GO

-- For each orphan reported above:
-- ALTER USER [pharmaverify_app] WITH LOGIN = [pharmaverify_app];
GO

PRINT 'Restore complete. Now run verify-restore.sql and php artisan migrate:status.';
GO
