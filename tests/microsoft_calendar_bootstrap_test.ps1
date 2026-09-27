# Windows prerequisite and orchestration tests with simulated module providers.
# The subprocess case starts real local PowerShell children with an inert fixture.
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '../tools/setup-microsoft-calendar-access.ps1')
$tenant = '11111111-1111-1111-1111-111111111111'
$app = '22222222-2222-2222-2222-222222222222'
$principal = '33333333-3333-3333-3333-333333333333'
$passed = 0
function Assert($Condition, [string]$Message) { if (-not $Condition) { throw "ASSERTION FAILED: $Message" } }
function Expect-Stop([scriptblock]$Action, [string]$Pattern) {
    $stopped = $false
    try { & $Action | Out-Null } catch { $stopped = $true; Assert ($_.Exception.Message -match $Pattern) "Unexpected exception: $_" }
    Assert $stopped 'Expected a stop'
}
function Reset-State {
    $script:available = @{ PackageManagement = @('1.0.0.1'); PowerShellGet = @('1.0.0.1') }
    $script:installs = @(); $script:imports = @(); $script:phases = @(); $script:receipts = @()
    $script:failModule = ''; $script:failPhase = ''; $script:badReceipt = $false
    $script:galleryExists = $true; $script:registrations = 0; $script:providerExists = $true; $script:providerInstalls = 0
    $script:reportedInstaller = '2.2.5'; $script:reportedSource = 'PowerShellGet'
}
function Run-Case([string]$Name, [scriptblock]$Action) {
    Reset-State
    & $Action 6>$null
    $script:passed++
    Write-Host "PASS $Name"
}
function Get-PSRepository {
    if ($script:galleryExists) { [pscustomobject]@{ Name = 'PSGallery'; SourceLocation = 'https://www.powershellgallery.com/api/v2' } }
}
function Register-PSRepository([switch]$Default) {
    Assert $Default 'Only Microsoft default repository registration'
    $script:registrations++; $script:galleryExists = $true
}
function Get-PackageProvider([switch]$ListAvailable) {
    if ($script:providerExists) { [pscustomobject]@{ Name = 'NuGet'; Version = [version]'2.8.5.201' } }
}
function Install-PackageProvider($Name, $MinimumVersion, $Scope, [switch]$Force) {
    Assert ($Name -eq 'NuGet' -and $Scope -eq 'CurrentUser' -and $MinimumVersion -eq '2.8.5.201') 'Scoped supported NuGet installation'
    $script:providerInstalls++; $script:providerExists = $true
}
function Get-Module($Name, [switch]$ListAvailable) {
    foreach ($version in $script:available[$Name]) { [pscustomobject]@{ Name = $Name; Version = [version]$version } }
}
function Find-Module($Name, $MinimumVersion, $Repository) { [pscustomobject]@{ Version = [version]$MinimumVersion } }
function Install-Module($Name, $RequiredVersion, $MinimumVersion, $Repository, $Scope, [switch]$Force, [switch]$AllowClobber, [switch]$SkipPublisherCheck) {
    if (-not $AllowClobber) { throw 'Existing PackageManagement commands require -AllowClobber.' }
    Assert ($Repository -eq 'PSGallery' -and $Scope -eq 'CurrentUser' -and -not $SkipPublisherCheck) 'Installation boundaries preserved'
    if ($Force) { throw 'Simulated loaded PackageManagement dependency cannot be overwritten.' }
    if ($Name -eq $script:failModule) { throw "Simulated install failure for $Name" }
    if ($Name -in @('Microsoft.Graph.Authentication', 'ExchangeOnlineManagement')) {
        Assert (('PackageManagement:1.4.8.1' -in $script:imports) -and ('PowerShellGet:2.2.5' -in $script:imports)) 'Supported package managers must be active before Microsoft tools'
    }
    $version = if ($RequiredVersion) { $RequiredVersion } else { $MinimumVersion }
    $script:installs += "${Name}:$version"
    $script:available[$Name] = @($version)
}
function Import-Module($Name, $RequiredVersion, [switch]$Force) { $script:imports += "${Name}:$RequiredVersion" }
function Get-Command($Name) {
    Assert ($Name -eq 'Install-Module') 'Only installer resolution is requested in these tests'
    [pscustomobject]@{ ModuleName = $script:reportedSource; Module = [pscustomobject]@{ Version = [version]$script:reportedInstaller } }
}

Run-Case 'old Windows package managers upgrade in dependency order and rerun skips them' {
    Initialize-SiteSeePackageManagers
    Assert (($script:installs -join ',') -eq 'PackageManagement:1.4.8.1,PowerShellGet:2.2.5') 'Dependency order'
    Assert ($script:imports.Count -eq 0) 'Old DLLs are not replaced in the running process'
    Initialize-SiteSeePackageManagers
    Assert ($script:installs.Count -eq 2) 'No reinstall of already available supported versions'
    Import-SiteSeePackageManagers
    Initialize-SiteSeeModules
    Assert ($script:installs.Count -eq 4) 'Both Microsoft tools installed after supported imports'
}
Run-Case 'missing gallery and NuGet are prepared automatically without trust changes' {
    $script:galleryExists = $false; $script:providerExists = $false
    Initialize-SiteSeePackageManagers
    Assert ($script:registrations -eq 1 -and $script:providerInstalls -eq 1) 'Prerequisites installed once'
}
Run-Case 'package manager installation failure stops before Microsoft tools' {
    $script:failModule = 'PackageManagement'
    Expect-Stop { Initialize-SiteSeePackageManagers } 'Simulated install failure'
    Assert ($script:installs.Count -eq 0 -and $script:imports.Count -eq 0) 'No later actions'
}
Run-Case 'old or shadowed Install-Module cannot be used after the fresh-process boundary' {
    $script:reportedInstaller = '1.0.0.1'
    Expect-Stop { Import-SiteSeePackageManagers } 'did not resolve'
    $script:reportedInstaller = '2.2.5'; $script:reportedSource = 'UnexpectedModule'
    Expect-Stop { Import-SiteSeePackageManagers } 'did not resolve'
    Assert ($script:installs.Count -eq 0) 'No installation with unresolved installer'
}

# Keep the real child launcher for a process-level smoke test at the end.
$realChildLauncher = ${function:Invoke-SiteSeeChild}
function Assert-SiteSeeRuntime { }
function Invoke-SiteSeeChild($ChildPhase, $Tenant, $App, $Receipt) {
    $script:phases += $ChildPhase; $script:receipts += $Receipt
    if ($ChildPhase -eq $script:failPhase) { return 1 }
    if ($ChildPhase -eq 'Directory') {
        @{ revision = $script:SetupRevision; tenant = $(if ($script:badReceipt) { $principal } else { $Tenant }); application = $App; principal = $principal; account = 'admin@example.com' } |
            ConvertTo-Json | Set-Content -LiteralPath $Receipt -Encoding UTF8
    }
    return 0
}
Run-Case 'full setup launches four sequential fresh phases and deletes identifier handoff' {
    Assert ((Start-SiteSeeSetup $tenant $app) -eq 0) 'Orchestration completed'
    Assert (($script:phases -join ',') -eq 'Prerequisites,Tools,Directory,Exchange') 'Fresh phase boundaries'
    Assert (@($script:receipts | Where-Object { Test-Path -LiteralPath $_ }).Count -eq 0) 'No handoff file remains'
}
Run-Case 'each failed child prevents all following phases' {
    foreach ($phase in @('Prerequisites', 'Tools', 'Directory', 'Exchange')) {
        $script:phases = @(); $script:failPhase = $phase
        Assert ((Start-SiteSeeSetup $tenant $app) -eq 1) 'Failure propagated'
        Assert ($script:phases[-1] -eq $phase) 'No later phase starts'
    }
}
Run-Case 'mismatched directory handoff stops before Exchange and is cleaned up' {
    $script:badReceipt = $true
    Assert ((Start-SiteSeeSetup $tenant $app) -eq 1) 'Mismatch fails'
    Assert ('Exchange' -notin $script:phases) 'No Exchange phase'
    Assert (@($script:receipts | Where-Object { Test-Path -LiteralPath $_ }).Count -eq 0) 'Handoff cleaned'
}
Run-Case 'invalid IDs produce a readable failure without launching children' {
    Assert ((Start-SiteSeeSetup 'not-an-id' $app) -eq 1) 'Invalid input fails'
    Assert ($script:phases.Count -eq 0) 'No child launched'
}
Run-Case 'verified administrator identity survives the handoff and blank identity is rejected' {
    $receiptFile = [IO.Path]::GetTempFileName()
    try {
        $values = @{ revision = $script:SetupRevision; tenant = $tenant; application = $app; principal = $principal; account = 'admin@example.com' }
        $values | ConvertTo-Json | Set-Content -LiteralPath $receiptFile -Encoding UTF8
        $verified = Read-SiteSeeReceipt $receiptFile $tenant $app
        Assert ($verified.Account -eq 'admin@example.com' -and $verified.Principal -eq $principal) 'Verified identity carried'
        $values.account = ''
        $values | ConvertTo-Json | Set-Content -LiteralPath $receiptFile -Encoding UTF8
        Expect-Stop { Read-SiteSeeReceipt $receiptFile $tenant $app } 'missing or invalid'
    } finally { Remove-Item -LiteralPath $receiptFile -Force }
}

# A real local process validates argument quoting and nonzero exit propagation.
# It executes only the inert fixture, never the setup script or Microsoft tools.
function Get-SiteSeeEngine {
    $executable = if ($PSVersionTable.PSEdition -eq 'Desktop') { 'powershell.exe' } elseif ([Environment]::OSVersion.Platform -eq [PlatformID]::Win32NT) { 'pwsh.exe' } else { 'pwsh' }
    return Join-Path $PSHOME $executable
}
Set-Item Function:Invoke-SiteSeeChild -Value $realChildLauncher
Run-Case 'real child process handles spaces in paths and preserves failure exit codes' {
    $originalFile = $script:SetupFile
    $directory = Join-Path ([IO.Path]::GetTempPath()) ('SiteSee process test ' + [guid]::NewGuid().ToString())
    New-Item -ItemType Directory -Path $directory | Out-Null
    try {
        $script:SetupFile = Join-Path $directory 'inert child.ps1'
        @'
param($TenantId,$ApplicationId,$Phase,$ReceiptPath)
if ($TenantId -ne '11111111-1111-1111-1111-111111111111' -or $ApplicationId -ne '22222222-2222-2222-2222-222222222222') { exit 91 }
if ($ReceiptPath -notlike '*receipt with spaces.json') { exit 92 }
if ($Phase -eq 'Prerequisites') { exit 0 }
exit 7
'@ | Set-Content -LiteralPath $script:SetupFile -Encoding UTF8
        $receipt = Join-Path $directory 'receipt with spaces.json'
        Assert ((Invoke-SiteSeeChild 'Prerequisites' $tenant $app $receipt) -eq 0) 'Success propagated'
        Assert ((Invoke-SiteSeeChild 'Tools' $tenant $app $receipt) -eq 7) 'Failure propagated'
    } finally {
        $script:SetupFile = $originalFile
        Remove-Item -LiteralPath $directory -Recurse -Force
    }
}
Write-Host "RESULT: $passed bootstrap/orchestration cases passed; Microsoft and Windows installation responses were simulated."
