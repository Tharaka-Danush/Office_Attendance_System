$ErrorActionPreference = 'Stop'
$php = 'C:\xampp\php\php.exe'
$env:ATTENDANCE_OFFICE_CIDR = Read-Host 'Actual office IPv4 subnet (example 192.168.1.0/24; use YOUR subnet)'
$env:ATTENDANCE_DB_ADMIN_USER = Read-Host 'MySQL administrator username (usually root)'
$secure = Read-Host 'MySQL administrator password (Enter if currently blank)' -AsSecureString
$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
try {
 $env:ATTENDANCE_DB_ADMIN_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
 & $php "$PSScriptRoot\setup_office_server.php"
 if ($LASTEXITCODE -ne 0) { throw 'Office setup did not complete.' }
} finally {
 [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
 Remove-Item Env:ATTENDANCE_DB_ADMIN_PASSWORD,Env:ATTENDANCE_DB_ADMIN_USER,Env:ATTENDANCE_OFFICE_CIDR -ErrorAction SilentlyContinue
}
