# Offline behavioral tests. No Microsoft module, sign-in, or provider calls.
$ErrorActionPreference = 'Stop'
$source = Join-Path $PSScriptRoot '../tools/setup-microsoft-calendar-access.ps1'
$parseErrors = $null
$tokens = $null
[System.Management.Automation.Language.Parser]::ParseFile((Resolve-Path $source), [ref]$tokens, [ref]$parseErrors) | Out-Null
if ($parseErrors.Count) { throw ($parseErrors | Out-String) }
. $source

$tenant = '11111111-1111-1111-1111-111111111111'
$app = '22222222-2222-2222-2222-222222222222'
$principal = '33333333-3333-3333-3333-333333333333'
$sales = '44444444-4444-4444-4444-444444444444'
$cro = '55555555-5555-5555-5555-555555555555'
$graphResource = '66666666-6666-6666-6666-666666666666'
$roleId = '77777777-7777-7777-7777-777777777777'
$scopeName = "SiteSee-Calendar-$app"
$assignmentName = "SiteSee-CalendarRW-$app"
$passed = 0

function Reset-State {
    $script:state = @{
        Tenant = $tenant; Principals = @(); Scopes = @(); Assignments = @(); Writes = @(); Reads = @()
        BroadScope = $false; BadExisting = $false; NegativeGranted = $false; FailGrant = $false
        TimeoutGrant = $false; WrongMailbox = $false; EntraRole = 'Mail.Send'; NextLink = ''
        EmptyRoles = $false; MissingRoleList = $false; GraphWrite = $false
    }
}
function Assert($Condition, [string]$Message) { if (-not $Condition) { throw "ASSERTION FAILED: $Message" } }
function Expect-Stop([scriptblock]$Action, [string]$Pattern) {
    $stopped = $false
    try { & $Action | Out-Null } catch { $stopped = $true; Assert ($_.Exception.Message -match $Pattern) "Unexpected exception: $_" }
    Assert $stopped 'Expected a stop'
}
function Get-OrganizationConfig { [pscustomobject]@{ ExternalDirectoryOrganizationId = $state.Tenant } }
function Get-EXOMailbox($Identity, $Properties) {
    if ($Identity -eq 'sales@re.sitesee.ai') {
        [pscustomobject]@{ PrimarySmtpAddress = $(if ($state.WrongMailbox) { 'other@example.com' } else { $Identity }); ExternalDirectoryObjectId = $sales }
    } elseif ($Identity -eq 'cro@sitesee.ai') {
        [pscustomobject]@{ PrimarySmtpAddress = $Identity; ExternalDirectoryObjectId = $cro }
    } else { throw 'Unexpected mailbox' }
}
function Get-ServicePrincipal { $state.Principals }
function New-ServicePrincipal($AppId, $ObjectId, $DisplayName) {
    Assert ($AppId -eq $app -and $ObjectId -eq $principal) 'Correct existing app must be used'
    $state.Writes += 'pointer'
    $state.Principals += [pscustomobject]@{ AppId = $AppId; ObjectId = $ObjectId }
}
function Get-ManagementScope { $state.Scopes }
function New-ManagementScope($Name, $RecipientRestrictionFilter) {
    $state.Writes += 'scope'
    $state.Scopes += [pscustomobject]@{ Name = $Name; RecipientFilter = "($RecipientRestrictionFilter)"; Exclusive = $false; RecipientRoot = $null }
}
function Get-Recipient($RecipientPreviewFilter, $ResultSize) {
    Assert ($RecipientPreviewFilter -match "EmailAddresses -eq 'smtp:sales@re.sitesee.ai'") 'Scope must select exact address'
    [pscustomobject]@{ ExternalDirectoryObjectId = $sales }
    if ($state.BroadScope) { [pscustomobject]@{ ExternalDirectoryObjectId = $cro } }
}
function Get-ManagementRoleAssignment { $state.Assignments }
function New-ManagementRoleAssignment($Name, $App, $Role, $CustomResourceScope) {
    Assert ($App -eq $principal -and $Role -eq 'Application Calendars.ReadWrite' -and $CustomResourceScope -eq $scopeName) 'Only scoped calendar role is permitted'
    if ($state.FailGrant) { throw 'Simulated assignment failure' }
    $state.Writes += 'assignment'
    $state.Assignments += [pscustomobject]@{ Name = $Name; RoleAssigneeName = $App; Role = $Role; Enabled = $true }
    if ($state.TimeoutGrant) { throw 'Simulated ambiguous timeout' }
}
function Test-ServicePrincipalAuthorization($Identity, $Resource) {
    Assert ($Identity -eq $principal) 'Correct principal must be checked'
    # Existing unrelated mail access must be preserved, including outside mailbox.
    [pscustomobject]@{ RoleName = 'Application Mail.Send'; GrantedPermissions = 'Mail.Send'; AllowedResourceScope = 'ExistingMail'; ScopeType = 'CustomRecipientScope'; InScope = $true }
    if ($state.BadExisting) {
        [pscustomobject]@{ RoleName = 'Application Exchange Full Access'; GrantedPermissions = 'Calendars.ReadWrite'; AllowedResourceScope = 'Organization'; ScopeType = 'Organization'; InScope = $true }
    }
    foreach ($assignment in $state.Assignments) {
        [pscustomobject]@{
            RoleName = 'Application Calendars.ReadWrite'; GrantedPermissions = 'Calendars.ReadWrite'; AllowedResourceScope = $scopeName
            ScopeType = 'CustomRecipientScope'; InScope = ($Resource -eq 'sales@re.sitesee.ai' -or $state.NegativeGranted)
        }
    }
}
function Remove-ManagementRoleAssignment($Identity, $Confirm) {
    Assert ($Identity -eq $assignmentName) 'Only the new assignment may be removed'
    $state.Writes += 'remove-assignment'; $state.Assignments = @()
}
function Remove-ManagementScope($Identity, $Confirm) {
    Assert ($Identity -eq $scopeName) 'Only the dedicated scope may be removed'
    if ($state.Assignments.Count) { throw 'Scope remains in use' }
    $state.Writes += 'remove-scope'; $state.Scopes = @()
}
function Invoke-MgGraphRequest($Method, $Uri, $OutputType) {
    Assert ($Method -eq 'GET') 'Graph must remain read-only'
    $state.Reads += $Uri
    if ($Uri -like '*appRoleAssignments*') {
        if ($state.MissingRoleList) { return [pscustomobject]@{} }
        $rows = if ($state.EmptyRoles) { @() } else { @([pscustomobject]@{ resourceId = $graphResource; appRoleId = $roleId }) }
        return [pscustomobject]@{ value = $rows; '@odata.nextLink' = $state.NextLink }
    }
    if ($Uri -like '*appId=*') { return [pscustomobject]@{ id = $principal; appId = $app; displayName = 'Existing SiteSee mail app' } }
    if ($Uri -like "*/$graphResource`?*") {
        return [pscustomobject]@{ appId = '00000003-0000-0000-c000-000000000000'; appRoles = @([pscustomobject]@{ id = $roleId; value = $state.EntraRole }) }
    }
    throw "Unexpected Graph read $Uri"
}
function Run-Case([string]$Name, [scriptblock]$Body) {
    Reset-State
    & $Body 6>$null
    $script:passed++
    Write-Host "PASS $Name"
}

Run-Case 'new setup grants exact mailbox scope and rerun makes no writes' {
    Assert ((Get-SiteSeeDirectoryApp $app) -eq $principal) 'Directory object ID'
    Invoke-SiteSeeCalendarGrant $tenant $app $principal
    Assert (($state.Writes -join ',') -eq 'pointer,scope,assignment') 'Only expected objects created'
    $before = $state.Writes.Count
    Invoke-SiteSeeCalendarGrant $tenant $app $principal
    Assert ($state.Writes.Count -eq $before) 'Unchanged rerun must not write'
}
Run-Case 'wrong tenant makes no writes' {
    $state.Tenant = $cro
    Expect-Stop { Invoke-SiteSeeCalendarGrant $tenant $app $principal } 'different tenant'
    Assert ($state.Writes.Count -eq 0) 'No wrong-tenant writes'
}
Run-Case 'wrong mailbox identity makes no writes' {
    $state.WrongMailbox = $true
    Expect-Stop { Invoke-SiteSeeCalendarGrant $tenant $app $principal } 'primary addresses'
    Assert ($state.Writes.Count -eq 0) 'No writes'
}
Run-Case 'preexisting broader Exchange permission stops before writes' {
    $state.Principals = @([pscustomobject]@{ AppId = $app; ObjectId = $principal }); $state.BadExisting = $true
    Expect-Stop { Invoke-SiteSeeCalendarGrant $tenant $app $principal } 'different role or scope'
    Assert ($state.Writes.Count -eq 0) 'No existing grant changed'
}
Run-Case 'scope preview blocks a second mailbox before authorization' {
    $state.BroadScope = $true
    Expect-Stop { Invoke-SiteSeeCalendarGrant $tenant $app $principal } 'exactly the sales mailbox'
    Assert ($state.Assignments.Count -eq 0 -and $state.Scopes.Count -eq 0) 'No access granted and new scope removed'
}
Run-Case 'negative authorization failure removes only newly created objects' {
    $state.NegativeGranted = $true
    Expect-Stop { Invoke-SiteSeeCalendarGrant $tenant $app $principal } 'mailbox boundary'
    Assert (($state.Writes -join ',') -eq 'pointer,scope,assignment,remove-assignment,remove-scope') 'New authorization rolled back'
    Assert ($state.Principals.Count -eq 1) 'Pointer kept without grants'
}
Run-Case 'role creation failure cleans up new scope' {
    $state.FailGrant = $true
    Expect-Stop { Invoke-SiteSeeCalendarGrant $tenant $app $principal } 'assignment failure'
    Assert ($state.Scopes.Count -eq 0 -and $state.Assignments.Count -eq 0) 'New scope removed'
}
Run-Case 'ambiguous remote success is reconciled on unchanged rerun' {
    $state.TimeoutGrant = $true
    Expect-Stop { Invoke-SiteSeeCalendarGrant $tenant $app $principal } 'ambiguous timeout'
    Assert ($state.Assignments.Count -eq 1 -and $state.Scopes.Count -eq 1) 'Uncertain remote objects retained'
    $state.TimeoutGrant = $false; $before = $state.Writes.Count
    Invoke-SiteSeeCalendarGrant $tenant $app $principal
    Assert ($state.Writes.Count -eq $before) 'No duplicate on rerun'
}
Run-Case 'existing scope mismatch is preserved' {
    $state.Scopes = @([pscustomobject]@{ Name = $scopeName; RecipientFilter = "Department -eq 'All'"; Exclusive = $false; RecipientRoot = $null })
    Expect-Stop { Invoke-SiteSeeCalendarGrant $tenant $app $principal } 'does not match'
    Assert ($state.Writes.Count -eq 0) 'No writes'
}
Run-Case 'Entra calendar and full-mailbox grants require review without mutation' {
    foreach ($role in @('Calendars.ReadWrite', 'Calendars.Read', 'MailboxItem.Read.All', 'MailboxFolder.ReadWrite.All', 'full_access_as_app', 'Exchange.ManageAsApp')) {
        $state.EntraRole = $role
        Expect-Stop { Get-SiteSeeDirectoryApp $app } 'needs review'
    }
    Assert ($state.Writes.Count -eq 0) 'No Entra writes'
}
Run-Case 'foreign permission pagination never requested' {
    $state.NextLink = 'https://evil.example/steal'
    Expect-Stop { Get-SiteSeeDirectoryApp $app } 'continuation'
    Assert (@($state.Reads | Where-Object { $_ -notlike 'https://graph.microsoft.com/*' }).Count -eq 0) 'No foreign requests'
}
Run-Case 'empty role list is valid but missing role list stops' {
    $state.EmptyRoles = $true
    Assert ((Get-SiteSeeDirectoryApp $app) -eq $principal) 'Empty list accepted'
    $state.MissingRoleList = $true
    Expect-Stop { Get-SiteSeeDirectoryApp $app } 'incomplete'
}
Run-Case 'secrets and invalid IDs rejected before use' {
    Expect-Stop { ConvertTo-SiteSeeGuid 'secret-value' 'Application ID' } 'complete ID'
    Expect-Stop { ConvertTo-SiteSeeGuid '00000000-0000-0000-0000-000000000000' 'Application ID' } 'cannot be empty'
    Expect-Stop { Get-SiteSeeGraph 'https://graph.microsoft.com/v1.0/users/sales@re.sitesee.ai/events' } 'Unexpected directory URL'
}

# Reproduce the user's Windows PowerShell bootstrap failure. These mocks avoid
# installing anything on the test machine and remain separate from RBAC tests.
function Get-PSRepository($Name) {
    Assert ($Name -eq 'PSGallery') 'Only the official named repository is allowed'
    [pscustomobject]@{ SourceLocation = $script:galleryLocation }
}
function Get-PackageProvider([switch]$ListAvailable) { [pscustomobject]@{ Name = 'NuGet'; Version = [version]'2.8.5.201' } }
function Get-Module($Name, [switch]$ListAvailable) {
    if ($script:modulesPresent) { [pscustomobject]@{ Name = $Name; Version = [version]'99.0.0' } }
}
function Install-Module($Name, $MinimumVersion, $Repository, $Scope, [switch]$Force, [switch]$AllowClobber, [switch]$SkipPublisherCheck) {
    if (-not $AllowClobber) { throw "The commands Find-Package,Install-Package,Uninstall-Package are already available. Use -AllowClobber." }
    Assert ($Repository -eq 'PSGallery' -and $Scope -eq 'CurrentUser' -and -not $SkipPublisherCheck) 'Official repository, user-only installation and publisher checks preserved'
    Assert ($Name -in @('Microsoft.Graph.Authentication', 'ExchangeOnlineManagement')) 'Only the requested Microsoft modules installed'
    $script:installedModules += $Name
}
Run-Case 'bootstrap handles dependency command overlap and skips installed modules on rerun' {
    $script:galleryLocation = 'https://www.powershellgallery.com/api/v2'
    $script:modulesPresent = $false; $script:installedModules = @()
    Initialize-SiteSeeModules
    Assert (($script:installedModules -join ',') -eq 'Microsoft.Graph.Authentication,ExchangeOnlineManagement') 'Both required modules installed despite command overlap'
    $script:modulesPresent = $true
    Initialize-SiteSeeModules
    Assert ($script:installedModules.Count -eq 2) 'Rerun does not reinstall existing modules'
    Assert ($state.Writes.Count -eq 0) 'No permission writes during bootstrap'
}
Run-Case 'bootstrap rejects redirected module repository before installation' {
    $script:galleryLocation = 'https://other.example/api/v2'
    $script:modulesPresent = $false; $script:installedModules = @()
    Expect-Stop { Initialize-SiteSeeModules } 'official PowerShell Gallery'
    Assert ($script:installedModules.Count -eq 0) 'No module installation from another repository'
}
Write-Host "RESULT: $passed offline behavioral cases passed. Microsoft responses were simulated."
