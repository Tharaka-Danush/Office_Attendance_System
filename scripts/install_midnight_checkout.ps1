$ErrorActionPreference = 'Stop'
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw 'Run PowerShell as Administrator.' }
$php = 'C:\xampp\php\php.exe'
if(-not (Test-Path $php)) { throw "PHP not found at $php" }
$script = Join-Path $PSScriptRoot 'midnight_auto_checkout.php'
$action = New-ScheduledTaskAction -Execute $php -Argument ('"'+$script+'"')
$trigger = New-ScheduledTaskTrigger -Daily -At '00:01'
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 10)
Register-ScheduledTask -TaskName 'Office Attendance Midnight Checkout' -Action $action -Trigger $trigger -Settings $settings -User 'SYSTEM' -RunLevel Highest -Force | Out-Null
Write-Host 'Midnight checkout installed at 00:01 Sri Lanka local time. Keep Windows timezone set to Sri Lanka.'
