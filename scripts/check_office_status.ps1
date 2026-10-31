$ErrorActionPreference = 'Stop'
$issues = 0
$php = 'C:\xampp\php\php.exe'
if (Test-Path $php) {
    & $php "$PSScriptRoot\check_office_setup.php"
    if ($LASTEXITCODE -ne 0) { $issues++ }
} else { Write-Host 'CHECK: XAMPP PHP not found at C:\xampp\php\php.exe.'; $issues++ }
try {
    $task = Get-ScheduledTask -TaskName 'Office Attendance Daily Backup' -ErrorAction Stop
    $info = $task | Get-ScheduledTaskInfo
    Write-Host ('Backup task state: ' + $task.State)
    Write-Host ('Last run: ' + $info.LastRunTime + '; next run: ' + $info.NextRunTime)
    Write-Host ('Last result: ' + $info.LastTaskResult)
    if ($task.State -eq 'Disabled' -or $info.LastRunTime.Year -lt 2000 -or $info.LastTaskResult -ne 0) {
        Write-Host 'CHECK: Backup must have a successful completed run (result 0).'; $issues++
    } else { Write-Host 'PASS: Backup task has a successful last run.' }
    if (-not ($task.Actions | Where-Object { $_.Arguments -like '*daily_backup.php*' })) {
        Write-Host 'CHECK: Task action does not reference daily_backup.php.'; $issues++
    }
} catch { Write-Host 'CHECK: Cannot inspect the backup task. Run this check as administrator; install with INSTALL_DAILY_BACKUP.bat if missing.'; $issues++ }
try {
    $latest = Get-ChildItem 'C:\xampp\attendance-backups' -Filter 'attendance_*.sql' -File -ErrorAction Stop | Sort-Object LastWriteTime -Descending | Select-Object -First 1
    if (-not $latest -or $latest.Length -eq 0 -or $latest.LastWriteTime -lt (Get-Date).AddHours(-36)) {
        Write-Host 'CHECK: No recent completed backup within 36 hours.'; $issues++
    } else {
        Write-Host ('PASS: Recent backup: ' + $latest.Name + ' (' + $latest.Length + ' bytes)')
        Write-Host 'A recent file does not prove restore works. Restore a copy into a separate test database.'
    }
} catch { Write-Host 'CHECK: Cannot read backup folder. Run this check as administrator.'; $issues++ }
Write-Host 'No configuration, records or scheduled tasks were changed.'
exit ([int]($issues -gt 0))
