$ErrorActionPreference = 'Stop'
$php = 'C:\xampp\php\php.exe'
$backup = Read-Host 'Backup SQL path (Enter = latest in C:\xampp\attendance-backups)'
$env:ATTENDANCE_DB_ADMIN_USER = Read-Host 'Local MySQL administrator username (usually root)'
$secure = Read-Host 'MySQL administrator password (Enter if blank)' -AsSecureString
$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
try {
    $env:ATTENDANCE_DB_ADMIN_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
    if ($backup) { & $php "$PSScriptRoot\verify_backup_restore.php" $backup }
    else { & $php "$PSScriptRoot\verify_backup_restore.php" }
    $result = $LASTEXITCODE
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
    Remove-Item Env:ATTENDANCE_DB_ADMIN_PASSWORD,Env:ATTENDANCE_DB_ADMIN_USER -ErrorAction SilentlyContinue
}
exit $result
