$ErrorActionPreference = 'Stop'
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw 'Right-click INSTALL_DAILY_BACKUP.bat and Run as administrator.' }
$folder = 'C:\xampp\attendance-backups'
New-Item -ItemType Directory -Path $folder -Force | Out-Null
& icacls $folder /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Cannot protect backup folder.' }
$arguments = '"' + "$PSScriptRoot\daily_backup.php" + '" "' + $folder + '"'
$action = New-ScheduledTaskAction -Execute 'C:\xampp\php\php.exe' -Argument $arguments
$trigger = New-ScheduledTaskTrigger -Daily -At '23:55'
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 1)
Register-ScheduledTask -TaskName 'Office Attendance Daily Backup' -Action $action -Trigger $trigger -Settings $settings -User 'SYSTEM' -RunLevel Highest -Force | Out-Null
Start-ScheduledTask -TaskName 'Office Attendance Daily Backup'
Write-Host 'Daily backup installed for 23:55. First backup started. Verify the SQL file and Task Scheduler Last Run Result = 0.'
