$ErrorActionPreference = 'Stop'
function Reveal([Security.SecureString]$Value) {
    $ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Value)
    try { return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr) }
}
try {
    $username = Read-Host 'Admin username (for example tharaka)'
    $password = Reveal (Read-Host 'New password (minimum 10 characters)' -AsSecureString)
    $confirm = Reveal (Read-Host 'Confirm new password' -AsSecureString)
    $project = Split-Path $PSScriptRoot -Parent
    $phpExe = Join-Path (Split-Path (Split-Path $project -Parent) -Parent) 'php\php.exe'
    if (!(Test-Path $phpExe)) { throw 'Run this from the installed C:\xampp\htdocs\Office_Attendance_System folder.' }
    $info = New-Object System.Diagnostics.ProcessStartInfo
    $info.FileName = $phpExe
    $info.Arguments = '"' + (Join-Path $PSScriptRoot 'reset_admin_password.php') + '"'
    $info.UseShellExecute = $false
    $info.RedirectStandardInput = $true
    $info.RedirectStandardOutput = $true
    $info.RedirectStandardError = $true
    $info.CreateNoWindow = $true
    $process = New-Object System.Diagnostics.Process
    $process.StartInfo = $info
    $process.Start() | Out-Null
    $process.StandardInput.WriteLine($username)
    $process.StandardInput.WriteLine($password)
    $process.StandardInput.WriteLine($confirm)
    $process.StandardInput.Close()
    $password = $null; $confirm = $null
    Write-Host $process.StandardOutput.ReadToEnd()
    Write-Host $process.StandardError.ReadToEnd()
    $process.WaitForExit()
} catch { Write-Host $_.Exception.Message -ForegroundColor Red }
Read-Host 'Press Enter to close'
