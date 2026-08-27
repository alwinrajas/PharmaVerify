/* ===========================================================================
   PharmaVerify - SQL Server backup
   ---------------------------------------------------------------------------
   Templates for the three backup types. Adjust @BackupRoot and schedule these
   through SQL Server Agent or the supplied Backup-PharmaVerify.ps1 wrapper.

   PharmaVerify itself runs none of this. See docs/18-BACKUP-AND-RECOVERY.md.
   =========================================================================== */

USE master;
GO

DECLARE @Database  sysname       = N'PharmaVerify';
DECLARE @BackupRoot nvarchar(260) = N'D:\Backups\PharmaVerify\';
DECLARE @Stamp     nvarchar(20)  = FORMAT(SYSDATETIME(), 'yyyyMMdd_HHmmss');
DECLARE @File      nvarchar(520);
DECLARE @Sql       nvarchar(max);

/* ---------------------------------------------------------------------------
   0. Recovery model
   ---------------------------------------------------------------------------
   FULL is required for log backups and therefore for point-in-time restore.
   Run once; it persists. Under SIMPLE the statements below will fail.
   --------------------------------------------------------------------------- */

IF EXISTS (SELECT 1 FROM sys.databases
           WHERE name = @Database AND recovery_model_desc <> 'FULL')
BEGIN
    SET @Sql = N'ALTER DATABASE ' + QUOTENAME(@Database) + N' SET RECOVERY FULL;';
    EXEC sp_executesql @Sql;
    PRINT 'Recovery model set to FULL.';
END
GO

/* ---------------------------------------------------------------------------
   1. FULL - daily, outside working hours
   ---------------------------------------------------------------------------
   CHECKSUM validates pages during the write, so corruption is found now rather
   than during a restore. COMPRESSION shortens both the backup and the restore.
   --------------------------------------------------------------------------- */

DECLARE @Database  sysname       = N'PharmaVerify';
DECLARE @BackupRoot nvarchar(260) = N'D:\Backups\PharmaVerify\';
DECLARE @Stamp     nvarchar(20)  = FORMAT(SYSDATETIME(), 'yyyyMMdd_HHmmss');
DECLARE @File      nvarchar(520);
DECLARE @Sql       nvarchar(max);

SET @File = @BackupRoot + @Database + N'_FULL_' + @Stamp + N'.bak';

SET @Sql = N'BACKUP DATABASE ' + QUOTENAME(@Database) + N'
    TO DISK = @f
    WITH INIT, COMPRESSION, CHECKSUM, STATS = 10,
         NAME = N''PharmaVerify full backup'';';
EXEC sp_executesql @Sql, N'@f nvarchar(520)', @f = @File;

/* Never trust an unverified backup. */
SET @Sql = N'RESTORE VERIFYONLY FROM DISK = @f WITH CHECKSUM;';
EXEC sp_executesql @Sql, N'@f nvarchar(520)', @f = @File;

PRINT 'Full backup written and verified: ' + @File;
GO

/* ---------------------------------------------------------------------------
   2. DIFFERENTIAL - every 6 hours during working hours
   ---------------------------------------------------------------------------
   Everything changed since the last FULL. Keeps the restore chain short.
   --------------------------------------------------------------------------- */

DECLARE @Database  sysname       = N'PharmaVerify';
DECLARE @BackupRoot nvarchar(260) = N'D:\Backups\PharmaVerify\';
DECLARE @Stamp     nvarchar(20)  = FORMAT(SYSDATETIME(), 'yyyyMMdd_HHmmss');
DECLARE @File      nvarchar(520);
DECLARE @Sql       nvarchar(max);

SET @File = @BackupRoot + @Database + N'_DIFF_' + @Stamp + N'.bak';

SET @Sql = N'BACKUP DATABASE ' + QUOTENAME(@Database) + N'
    TO DISK = @f
    WITH DIFFERENTIAL, INIT, COMPRESSION, CHECKSUM, STATS = 10;';
EXEC sp_executesql @Sql, N'@f nvarchar(520)', @f = @File;

PRINT 'Differential backup written: ' + @File;
GO

/* ---------------------------------------------------------------------------
   3. TRANSACTION LOG - every 15 minutes
   ---------------------------------------------------------------------------
   This interval is the RPO. It also truncates the log; without it the log file
   grows until the volume fills.
   --------------------------------------------------------------------------- */

DECLARE @Database  sysname       = N'PharmaVerify';
DECLARE @BackupRoot nvarchar(260) = N'D:\Backups\PharmaVerify\';
DECLARE @Stamp     nvarchar(20)  = FORMAT(SYSDATETIME(), 'yyyyMMdd_HHmmss');
DECLARE @File      nvarchar(520);
DECLARE @Sql       nvarchar(max);

SET @File = @BackupRoot + @Database + N'_LOG_' + @Stamp + N'.trn';

SET @Sql = N'BACKUP LOG ' + QUOTENAME(@Database) + N'
    TO DISK = @f
    WITH INIT, COMPRESSION, CHECKSUM;';
EXEC sp_executesql @Sql, N'@f nvarchar(520)', @f = @File;

PRINT 'Log backup written: ' + @File;
GO

/* ---------------------------------------------------------------------------
   4. What backups exist?
   ---------------------------------------------------------------------------
   Run this to confirm the schedule is actually running. A silently failing job
   is worse than no job, because it is trusted.
   --------------------------------------------------------------------------- */

SELECT TOP (50)
       bs.database_name,
       CASE bs.type WHEN 'D' THEN 'Full'
                    WHEN 'I' THEN 'Differential'
                    WHEN 'L' THEN 'Log'
                    ELSE bs.type END        AS backup_type,
       bs.backup_start_date,
       bs.backup_finish_date,
       DATEDIFF(SECOND, bs.backup_start_date, bs.backup_finish_date) AS seconds,
       CAST(bs.backup_size  / 1048576.0 AS decimal(10,2)) AS size_mb,
       CAST(bs.compressed_backup_size / 1048576.0 AS decimal(10,2)) AS compressed_mb,
       bmf.physical_device_name
FROM   msdb.dbo.backupset bs
JOIN   msdb.dbo.backupmediafamily bmf ON bmf.media_set_id = bs.media_set_id
WHERE  bs.database_name = N'PharmaVerify'
ORDER  BY bs.backup_start_date DESC;
GO
