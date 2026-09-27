#requires -Version 5.1
<#
SiteSee: add mailbox-scoped Microsoft calendar authorization to the EXISTING app.
Run in a normal Windows PowerShell window. No client secret is requested.
Only Exchange Application RBAC objects are created. Existing grants are retained.
No Graph calendar/event/mail write, booking change, or migration is performed.
#>
[CmdletBinding()]
param(
    [string]$TenantId,
    [string]$ApplicationId,
    [ValidateSet('Setup', 'Prerequisites', 'Tools', 'Directory', 'Exchange')]
    [string]$Phase = 'Setup',
    [string]$ReceiptPath
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$script:SetupRevision = '20260927-r4'
$script:SetupFile = $PSCommandPath
$script:SetupStage = 'startup'
$script:ExchangeIdentitySummary = @()

function Get-SiteSeeValue($Object, [string]$Name) {
    if ($null -eq $Object) { return $null }
    if ($Object -is [System.Collections.IDictionary]) { return $Object[$Name] }
    $property = $Object.PSObject.Properties[$Name]
    if ($null -ne $property) { return $property.Value }
    return $null
}

function ConvertTo-SiteSeeGuid([string]$Value, [string]$Label) {
    if ($Value -notmatch '^[0-9a-fA-F]{8}(-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}$') {
        throw "$Label must be the complete ID shown by the server; do not enter a secret."
    }
    if ([guid]$Value -eq [guid]::Empty) { throw "$Label cannot be empty." }
    return ([guid]$Value).ToString()
}

function Get-SiteSeeGraph([string]$Uri) {
    # Directory reads only. Never follow a continuation to another host or API.
    if ($Uri -notmatch '^https://graph\.microsoft\.com/v1\.0/servicePrincipals(?:[/\(?]|$)') {
        throw 'Unexpected directory URL; stopped.'
    }
    return Invoke-MgGraphRequest -Method GET -Uri $Uri -OutputType PSObject
}

function Get-SiteSeeDirectoryApp([string]$AppId) {
    $app = Get-SiteSeeGraph "https://graph.microsoft.com/v1.0/servicePrincipals(appId='$AppId')?`$select=id,appId,displayName"
    if ([string](Get-SiteSeeValue $app 'appId') -ne $AppId) { throw 'Microsoft returned a different application.' }
    $objectId = ConvertTo-SiteSeeGuid ([string](Get-SiteSeeValue $app 'id')) 'Enterprise application object ID'
    $next = "https://graph.microsoft.com/v1.0/servicePrincipals/$objectId/appRoleAssignments?`$select=resourceId,appRoleId"
    $resources = @{}
    $seen = @{}
    $roleCount = 0
    while ($next) {
        if ($seen.ContainsKey($next) -or $seen.Count -ge 20) { throw 'Unexpected directory pagination; stopped.' }
        # Continuations must retain this application's role-assignment endpoint.
        $allowed = '^https://graph\.microsoft\.com/v1\.0/servicePrincipals/' + [regex]::Escape($objectId) + '/appRoleAssignments(?:\?|$)'
        if ($next -notmatch $allowed) { throw 'Unexpected permission continuation; stopped.' }
        $seen[$next] = $true
        $page = Get-SiteSeeGraph $next
        $rows = Get-SiteSeeValue $page 'value'
        $hasValue = if ($page -is [System.Collections.IDictionary]) { $page.Contains('value') } else { $null -ne $page.PSObject.Properties['value'] }
        if (-not $hasValue) { throw 'Directory permission response was incomplete.' }
        foreach ($row in $rows) {
            $resourceId = ConvertTo-SiteSeeGuid ([string](Get-SiteSeeValue $row 'resourceId')) 'Resource ID'
            $roleId = ConvertTo-SiteSeeGuid ([string](Get-SiteSeeValue $row 'appRoleId')) 'Permission ID'
            if (-not $resources.ContainsKey($resourceId)) {
                $resources[$resourceId] = Get-SiteSeeGraph "https://graph.microsoft.com/v1.0/servicePrincipals/${resourceId}?`$select=appId,appRoles"
            }
            $resource = $resources[$resourceId]
            $roles = @(Get-SiteSeeValue $resource 'appRoles' | Where-Object { [string](Get-SiteSeeValue $_ 'id') -eq $roleId })
            if ($roles.Count -ne 1) { throw 'An existing application permission could not be identified; nothing will be broadened.' }
            $role = [string](Get-SiteSeeValue $roles[0] 'value')
            $resourceApp = [string](Get-SiteSeeValue $resource 'appId')
            # These resource IDs identify Microsoft Graph and Exchange Online.
            if ($resourceApp -in @('00000003-0000-0000-c000-000000000000', '00000002-0000-0ff1-ce00-000000000000')) {
                if ($role -match 'Calendars|MailboxItem|MailboxFolder|full_access_as_app|EWS\.AccessAsApp|Exchange\.ManageAsApp') {
                    throw "Existing Entra permission '$role' needs review before adding scoped calendar access. It was not changed."
                }
            }
            $roleCount++
        }
        $next = [string](Get-SiteSeeValue $page '@odata.nextLink')
    }
    Write-Host "Existing application: $([string](Get-SiteSeeValue $app 'displayName'))"
    Write-Host "Existing Entra application grants inspected: $roleCount; unchanged."
    return $objectId
}

function Test-SiteSeeCalendarRole($Row) {
    $text = [string](Get-SiteSeeValue $Row 'RoleName') + ' ' + [string](Get-SiteSeeValue $Row 'GrantedPermissions')
    return $text -match 'Calendars|MailboxItem|MailboxFolder|Exchange Full Access|EWS\.AccessAsApp'
}

function Assert-SiteSeeAuthorization($Rows, [string]$ScopeName, [bool]$RequireGrant, [bool]$ExpectedInScope) {
    $calendarRows = @($Rows | Where-Object { Test-SiteSeeCalendarRole $_ })
    foreach ($row in $calendarRows) {
        if ([string](Get-SiteSeeValue $row 'RoleName') -ne 'Application Calendars.ReadWrite' -or
            [string](Get-SiteSeeValue $row 'AllowedResourceScope') -ne $ScopeName -or
            [string](Get-SiteSeeValue $row 'ScopeType') -ne 'CustomRecipientScope') {
            throw 'An existing Exchange calendar grant has a different role or scope. It was not changed.'
        }
        $inScope = Get-SiteSeeValue $row 'InScope'
        if ([string]$inScope -notin @('True', 'False') -or [string]$inScope -ne [string]$ExpectedInScope) {
            throw 'Calendar authorization did not match the required mailbox boundary.'
        }
    }
    if ($RequireGrant -and $calendarRows.Count -ne 1) { throw 'The single expected calendar authorization was not verified.' }
}

function Assert-SiteSeeScope($Scope, [string]$ExpectedFilter, [string]$MailboxObjectId) {
    $actualFilter = [string](Get-SiteSeeValue $Scope 'RecipientFilter')
    if (($actualFilter -replace '[()\s]', '') -ne ($ExpectedFilter -replace '[()\s]', '') -or
        [string](Get-SiteSeeValue $Scope 'Exclusive') -ne 'False' -or
        [string](Get-SiteSeeValue $Scope 'RecipientRoot')) {
        throw 'The existing scope does not match the dedicated mailbox scope; it was not changed.'
    }
    $members = @(Get-Recipient -RecipientPreviewFilter $actualFilter -ResultSize Unlimited)
    if ($members.Count -ne 1 -or [string](Get-SiteSeeValue $members[0] 'ExternalDirectoryObjectId') -ne $MailboxObjectId) {
        throw 'The calendar scope must match exactly the sales mailbox; verification failed.'
    }
}

function Assert-SiteSeeExchangeTenant([string]$ExpectedTenant) {
    # This process has just opened one Exchange connection. Do not infer tenant
    # mismatch from a missing organization property or accept delegated routing.
    $connections = @(Get-ConnectionInformation | Where-Object { [string](Get-SiteSeeValue $_ 'State') -eq 'Connected' })
    if ($connections.Count -ne 1) { throw 'A single active Exchange connection could not be verified. No permission was changed.' }
    $connection = $connections[0]
    $connectedTenant = ([string](Get-SiteSeeValue $connection 'TenantID')).Trim()
    $signedIn = [string](Get-SiteSeeValue $connection 'UserPrincipalName')
    $organizations = @(Get-OrganizationConfig)
    if ($organizations.Count -ne 1) { throw 'A single Exchange organization could not be verified. No permission was changed.' }
    $organizationTenant = ([string](Get-SiteSeeValue $organizations[0] 'ExternalDirectoryOrganizationId')).Trim()
    $script:ExchangeIdentitySummary = @(
        "Expected server tenant: $ExpectedTenant",
        "Exchange signed-in account: $signedIn",
        "Exchange connection tenant: $(if ($connectedTenant) { $connectedTenant } else { '[not returned]' })",
        "Exchange organization tenant: $(if ($organizationTenant) { $organizationTenant } else { '[not returned]' })"
    )
    foreach ($line in $script:ExchangeIdentitySummary) { Write-Host $line }
    if ([string](Get-SiteSeeValue $connection 'DelegatedOrganization') -or
        [string](Get-SiteSeeValue $connection 'IsEopSession') -ne 'False') {
        throw 'This setup requires a direct Exchange Online connection to the SiteSee tenant. No permission was changed.'
    }
    if (-not $connectedTenant) { throw 'Exchange did not return its active connection tenant ID; identity remains unverified. No permission was changed.' }
    $connectedTenant = ConvertTo-SiteSeeGuid $connectedTenant 'Active Exchange tenant ID'
    if ($connectedTenant -ne $ExpectedTenant) {
        throw 'The active Exchange connection belongs to a different tenant. Use an administrator in the expected server tenant. No permission was changed.'
    }
    if ($organizationTenant) {
        $organizationTenant = ConvertTo-SiteSeeGuid $organizationTenant 'Exchange organization tenant ID'
        if ($organizationTenant -ne $ExpectedTenant) {
            throw 'Exchange connection and organization tenant IDs disagree. No permission was changed.'
        }
    } else {
        Write-Host 'Organization tenant field was not returned; direct active Exchange connection tenant verified.'
    }
}

function ConvertTo-SiteSeeAdminAccount([string]$Account) {
    if ($Account -notmatch '^[^\s@]+@[^\s@]+$' -or $Account.Length -gt 320) {
        throw 'The administrator account from the verified directory sign-in was missing or invalid.'
    }
    return $Account
}

function Invoke-SiteSeeCalendarGrant([string]$ExpectedTenant, [string]$AppId, [string]$ObjectId) {
    $mailbox = 'sales@re.sitesee.ai'
    $outsideMailbox = 'cro@sitesee.ai'
    $scopeName = "SiteSee-Calendar-$AppId"
    $assignmentName = "SiteSee-CalendarRW-$AppId"
    $filter = "EmailAddresses -eq 'smtp:$mailbox'"
    $stage = 'checking tenant and mailbox identities'
    $newScope = $false
    $newAssignment = $false
    try {
        Assert-SiteSeeExchangeTenant $ExpectedTenant
        $target = Get-EXOMailbox -Identity $mailbox -Properties ExternalDirectoryObjectId,PrimarySmtpAddress
        $outside = Get-EXOMailbox -Identity $outsideMailbox -Properties ExternalDirectoryObjectId,PrimarySmtpAddress
        if ([string](Get-SiteSeeValue $target 'PrimarySmtpAddress') -ne $mailbox -or
            [string](Get-SiteSeeValue $outside 'PrimarySmtpAddress') -ne $outsideMailbox) {
            throw 'The mailbox primary addresses differ from the approved addresses; no permission was changed.'
        }
        $mailboxObjectId = ConvertTo-SiteSeeGuid ([string](Get-SiteSeeValue $target 'ExternalDirectoryObjectId')) 'Sales mailbox ID'
        $outsideObjectId = ConvertTo-SiteSeeGuid ([string](Get-SiteSeeValue $outside 'ExternalDirectoryObjectId')) 'Test recipient mailbox ID'
        if ($mailboxObjectId -eq $outsideObjectId) { throw 'Sales and test recipient unexpectedly identify the same mailbox.' }

        $stage = 'inspecting existing Exchange authorization'
        $principals = @(Get-ServicePrincipal | Where-Object {
            [string](Get-SiteSeeValue $_ 'AppId') -eq $AppId -or [string](Get-SiteSeeValue $_ 'ObjectId') -eq $ObjectId
        })
        if ($principals.Count -gt 1 -or ($principals.Count -eq 1 -and (
            [string](Get-SiteSeeValue $principals[0] 'AppId') -ne $AppId -or
            [string](Get-SiteSeeValue $principals[0] 'ObjectId') -ne $ObjectId))) {
            throw 'The existing Exchange application pointer does not match the directory identity.'
        }
        $scopes = @(Get-ManagementScope | Where-Object { [string](Get-SiteSeeValue $_ 'Name') -eq $scopeName })
        if ($scopes.Count -gt 1) { throw 'Multiple matching scopes were returned.' }
        if ($scopes.Count -eq 1) { Assert-SiteSeeScope $scopes[0] $filter $mailboxObjectId }
        $assignments = @(Get-ManagementRoleAssignment | Where-Object { [string](Get-SiteSeeValue $_ 'Name') -eq $assignmentName })
        if ($assignments.Count -gt 1) { throw 'Multiple matching role assignments were returned.' }
        if ($assignments.Count -eq 1) {
            if ([string](Get-SiteSeeValue $assignments[0] 'RoleAssigneeName') -ne $ObjectId -or
                [string](Get-SiteSeeValue $assignments[0] 'Role') -ne 'Application Calendars.ReadWrite' -or
                [string](Get-SiteSeeValue $assignments[0] 'Enabled') -ne 'True' -or $scopes.Count -ne 1) {
                throw 'The existing named role assignment differs from this setup; it was not changed.'
            }
        }
        if ($principals.Count -eq 1) {
            Assert-SiteSeeAuthorization @(Test-ServicePrincipalAuthorization -Identity $ObjectId -Resource $mailbox) $scopeName ($assignments.Count -eq 1) $true
            Assert-SiteSeeAuthorization @(Test-ServicePrincipalAuthorization -Identity $ObjectId -Resource $outsideMailbox) $scopeName ($assignments.Count -eq 1) $false
        } elseif ($assignments.Count -ne 0) { throw 'A role assignment exists without the expected application pointer.' }

        $stage = 'registering the existing application in Exchange'
        if ($principals.Count -eq 0) {
            New-ServicePrincipal -AppId $AppId -ObjectId $ObjectId -DisplayName "SiteSee existing mail application $AppId" | Out-Null
        }
        $stage = 'creating the dedicated mailbox scope'
        if ($scopes.Count -eq 0) {
            New-ManagementScope -Name $scopeName -RecipientRestrictionFilter $filter | Out-Null
            $newScope = $true
        }
        # Read back and preview before any permission is granted.
        $verifiedScopes = @(Get-ManagementScope | Where-Object { [string](Get-SiteSeeValue $_ 'Name') -eq $scopeName })
        if ($verifiedScopes.Count -ne 1) { throw 'The dedicated scope could not be read back.' }
        Assert-SiteSeeScope $verifiedScopes[0] $filter $mailboxObjectId

        $stage = 'assigning calendar access to the dedicated scope'
        if ($assignments.Count -eq 0) {
            New-ManagementRoleAssignment -Name $assignmentName -App $ObjectId -Role 'Application Calendars.ReadWrite' -CustomResourceScope $scopeName | Out-Null
            $newAssignment = $true
        }
        $stage = 'verifying allowed and excluded mailboxes'
        Assert-SiteSeeAuthorization @(Test-ServicePrincipalAuthorization -Identity $ObjectId -Resource $mailbox) $scopeName $true $true
        Assert-SiteSeeAuthorization @(Test-ServicePrincipalAuthorization -Identity $ObjectId -Resource $outsideMailbox) $scopeName $true $false
        Write-Host ''
        Write-Host 'FINAL RESULTS' -ForegroundColor Green
        Write-Host 'Microsoft calendar permission configuration: PASS'
        Write-Host "Application ID: $AppId"
        Write-Host "Enterprise application object ID: $ObjectId"
        Write-Host "Calendar read/write scope: $mailbox only"
        Write-Host "Excluded mailbox check: $outsideMailbox PASS"
        Write-Host "Scope: $scopeName"
        Write-Host "Assignment: $assignmentName"
        Write-Host 'Existing mail permissions, app secrets, Stripe and Zoho: unchanged'
        Write-Host 'No calendar event, invitation, email or booking was created or changed.'
        Write-Host 'Actual server calendar access: NOT YET VERIFIED'
        Write-Host 'Calendar migration: NOT ACTIVATED; SiteSee remains in TEST mode.'
        Write-Host 'Next WHM command: python3 /home/sitesee/check-microsoft-calendar.py'
        Write-Host 'If that check still returns 403, Microsoft may take 30 minutes to 2 hours to apply cached permissions.'
        Write-Host 'Do not add tenant-wide calendar permissions or change keys to work around that delay.'
    } catch {
        Write-Host ''
        Write-Host "STOP while $stage" -ForegroundColor Red
        # Remove only objects whose creation this invocation confirmed. An ambiguous
        # remote timeout must be reconciled by rerun, never called a complete rollback.
        if ($newAssignment) {
            try {
                Remove-ManagementRoleAssignment -Identity $assignmentName -Confirm:$false
                Write-Host 'The role assignment created in this run was removed.'
            } catch { Write-Host "REVIEW REQUIRED: could not remove new assignment $assignmentName" }
        }
        if ($newScope) {
            try {
                Remove-ManagementScope -Identity $scopeName -Confirm:$false
                Write-Host 'The scope created in this run was removed.'
            } catch { Write-Host "REVIEW REQUIRED: could not remove new scope $scopeName" }
        }
        Write-Host 'Existing permissions were not edited. An Exchange application pointer may have been registered.'
        Write-Host 'A timed-out request may have completed remotely; an unchanged rerun inspects named objects first.'
        throw
    }
}

function Assert-SiteSeeRuntime {
    if ([Environment]::OSVersion.Platform -ne [PlatformID]::Win32NT -or
        $PSVersionTable.PSEdition -ne 'Desktop' -or $PSVersionTable.PSVersion.Major -ne 5) {
        throw 'Run this installer in Windows PowerShell 5.1 using the supplied Windows command.'
    }
    if (-not [Environment]::Is64BitProcess) { throw 'Use 64-bit Windows PowerShell from the Windows Start menu.' }
    $framework = Get-ItemProperty -LiteralPath 'HKLM:\SOFTWARE\Microsoft\NET Framework Setup\NDP\v4\Full' -Name Release
    if ([int]$framework.Release -lt 461808) { throw 'Microsoft tools require .NET Framework 4.7.2 or later. No permissions were changed.' }
}

function Initialize-SiteSeeGallery {
    [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
    $galleries = @(Get-PSRepository | Where-Object { $_.Name -eq 'PSGallery' })
    if ($galleries.Count -eq 0) {
        Register-PSRepository -Default
        $galleries = @(Get-PSRepository | Where-Object { $_.Name -eq 'PSGallery' })
    }
    if ($galleries.Count -ne 1 -or [string]$galleries[0].SourceLocation -notmatch '^https://www\.powershellgallery\.com/api/v2/?$') {
        throw 'PSGallery is not pointing to the official PowerShell Gallery.'
    }
    if (-not @(Get-PackageProvider -ListAvailable | Where-Object { $_.Name -eq 'NuGet' -and $_.Version -ge [version]'2.8.5.201' }).Count) {
        Install-PackageProvider -Name NuGet -MinimumVersion 2.8.5.201 -Scope CurrentUser -Force | Out-Null
    }
}

function Initialize-SiteSeePackageManagers {
    $script:SetupStage = 'checking the official module repository and NuGet provider'
    foreach ($name in @('PackageManagement', 'PowerShellGet')) {
        $versions = @(Get-Module -ListAvailable -Name $name | ForEach-Object { [string]$_.Version })
        Write-Host "Installed ${name}: $($versions -join ', ')"
    }
    Initialize-SiteSeeGallery
    # Install supported package managers explicitly, then EXIT this child process.
    # Installing them does not unload old DLLs already in this PowerShell session.
    foreach ($module in @(
        @{ Name = 'PackageManagement'; Version = '1.4.8.1' },
        @{ Name = 'PowerShellGet'; Version = '2.2.5' }
    )) {
        $script:SetupStage = "preparing $($module.Name) $($module.Version)"
        $available = @(Get-Module -ListAvailable -Name $module.Name | Where-Object { $_.Version -eq [version]$module.Version })
        if ($available.Count -eq 0) {
            Write-Host "Installing prerequisite $($module.Name) $($module.Version) for your Windows account..."
            Write-Host 'If PowerShell asks to install from PSGallery, choose Y for this official Microsoft module.'
            Install-Module -Name $module.Name -RequiredVersion $module.Version -Repository PSGallery -Scope CurrentUser -AllowClobber
        }
        $verified = @(Get-Module -ListAvailable -Name $module.Name | Where-Object { $_.Version -eq [version]$module.Version })
        if ($verified.Count -eq 0) { throw "The installation of $($module.Name) did not produce the required version." }
    }
    Write-Host 'Prerequisites ready. Continuing automatically in a fresh Windows PowerShell process.'
}

function Import-SiteSeePackageManagers {
    $script:SetupStage = 'loading the supported package managers in a fresh process'
    Import-Module PackageManagement -RequiredVersion 1.4.8.1 -Force
    Import-Module PowerShellGet -RequiredVersion 2.2.5 -Force
    $installer = Get-Command Install-Module
    if ($installer.ModuleName -ne 'PowerShellGet' -or $installer.Module.Version -ne [version]'2.2.5') {
        throw 'Install-Module did not resolve to PowerShellGet 2.2.5; stopped before Microsoft tool installation.'
    }
    Write-Host 'Active package managers: PackageManagement 1.4.8.1; PowerShellGet 2.2.5'
}

function Initialize-SiteSeeModules {
    Initialize-SiteSeeGallery
    foreach ($module in @(
        @{ Name = 'Microsoft.Graph.Authentication'; Minimum = '2.0.0' },
        @{ Name = 'ExchangeOnlineManagement'; Minimum = '3.7.0' }
    )) {
        $script:SetupStage = "installing or checking $($module.Name)"
        if (-not @(Get-Module -ListAvailable -Name $module.Name | Where-Object { $_.Version -ge [version]$module.Minimum }).Count) {
            Write-Host "Installing $($module.Name) for your Windows account..."
            # Resolve an exact version for side-by-side installation. Force would
            # reinstall dependencies, including already loaded package managers.
            $candidate = Find-Module -Name $module.Name -MinimumVersion $module.Minimum -Repository PSGallery
            $version = [version]$candidate.Version
            if ($version -lt [version]$module.Minimum) { throw 'The gallery returned an unsupported module version.' }
            Write-Host 'If PowerShell asks to install from PSGallery, choose Y for this official Microsoft module.'
            Install-Module -Name $module.Name -RequiredVersion $version.ToString() -Repository PSGallery -Scope CurrentUser -AllowClobber
        }
        $verified = @(Get-Module -ListAvailable -Name $module.Name | Where-Object { $_.Version -ge [version]$module.Minimum })
        if ($verified.Count -eq 0) { throw "The installation of $($module.Name) could not be verified." }
        Write-Host "$($module.Name) available: $(($verified | Sort-Object Version -Descending | Select-Object -First 1).Version)"
    }
}

function Get-SiteSeeEngine {
    $engine = Join-Path ([Environment]::GetFolderPath('System')) 'WindowsPowerShell\v1.0\powershell.exe'
    if (-not (Test-Path -LiteralPath $engine -PathType Leaf)) { throw 'Windows PowerShell executable was not found.' }
    return $engine
}

function Invoke-SiteSeeChild([string]$ChildPhase, [string]$Tenant, [string]$App, [string]$Receipt) {
    # Every phase uses the exact same file and a new process. No tokens cross
    # process boundaries and Graph/Exchange authentication assemblies never mix.
    $engine = Get-SiteSeeEngine
    & $engine -NoLogo -NoProfile -ExecutionPolicy RemoteSigned -File $script:SetupFile -TenantId $Tenant -ApplicationId $App -Phase $ChildPhase -ReceiptPath $Receipt | Out-Host
    return [int]$LASTEXITCODE
}

function Read-SiteSeeReceipt([string]$Path, [string]$Tenant, [string]$App) {
    $item = Get-Item -LiteralPath $Path
    if ($item.Length -le 0 -or $item.Length -gt 4096 -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        throw 'The directory verification handoff is missing or invalid.'
    }
    $receipt = Get-Content -LiteralPath $Path -Raw | ConvertFrom-Json
    if ([string](Get-SiteSeeValue $receipt 'revision') -ne $script:SetupRevision -or
        [string](Get-SiteSeeValue $receipt 'tenant') -ne $Tenant -or
        [string](Get-SiteSeeValue $receipt 'application') -ne $App) { throw 'Directory verification does not match this run.' }
    return [pscustomobject]@{
        Principal = ConvertTo-SiteSeeGuid ([string](Get-SiteSeeValue $receipt 'principal')) 'Verified enterprise application ID'
        Account = ConvertTo-SiteSeeAdminAccount ([string](Get-SiteSeeValue $receipt 'account'))
    }
}

function Start-SiteSeeSetup([string]$Tenant, [string]$App, [string]$RunPhase = 'Setup', [string]$Receipt = '') {
    $graphConnected = $false
    $exchangeConnected = $false
    $temporaryReceipt = ''
    $script:ExchangeIdentitySummary = @()
    try {
        Write-Host "SiteSee Microsoft calendar setup | revision $script:SetupRevision | phase $RunPhase"
        Write-Host "Installer: $script:SetupFile"
        Write-Host "SHA256: $((Get-FileHash -LiteralPath $script:SetupFile -Algorithm SHA256).Hash)"
        $script:SetupStage = 'checking Windows PowerShell and .NET prerequisites'
        Assert-SiteSeeRuntime
        Write-Host 'Uses the existing application. Enter IDs only; no secret or API key is needed.'
        if (-not $Tenant) { $Tenant = (Read-Host 'Tenant ID from WHM').Trim() }
        if (-not $App) { $App = (Read-Host 'Application ID from WHM').Trim() }
        $Tenant = ConvertTo-SiteSeeGuid $Tenant 'Tenant ID'
        $App = ConvertTo-SiteSeeGuid $App 'Application ID'
        switch ($RunPhase) {
            'Setup' {
                # Handoff contains only public identifiers, never a token/secret.
                $temporaryReceipt = [IO.Path]::GetTempFileName()
                foreach ($nextPhase in @('Prerequisites', 'Tools', 'Directory', 'Exchange')) {
                    $script:SetupStage = "running $nextPhase in a fresh process"
                    $code = Invoke-SiteSeeChild $nextPhase $Tenant $App $temporaryReceipt
                    if ($code -ne 0) { return 1 }
                    if ($nextPhase -eq 'Directory') { Read-SiteSeeReceipt $temporaryReceipt $Tenant $App | Out-Null }
                }
            }
            'Prerequisites' { Initialize-SiteSeePackageManagers }
            'Tools' {
                Import-SiteSeePackageManagers
                Initialize-SiteSeeModules
            }
            'Directory' {
                $script:SetupStage = 'loading Microsoft Graph Authentication'
                Import-Module Microsoft.Graph.Authentication -MinimumVersion 2.0.0
                $script:SetupStage = 'Microsoft administrator directory sign-in'
                Write-Host 'Sign in with your Microsoft 365 administrator. The directory check requests read-only Application.Read.All.'
                Connect-MgGraph -TenantId $Tenant -Scopes 'Application.Read.All' -ContextScope Process -NoWelcome | Out-Null
                $graphConnected = $true
                $context = Get-MgContext
                if ([string]$context.TenantId -ne $Tenant) { throw 'The directory sign-in belongs to a different tenant.' }
                $adminAccount = ConvertTo-SiteSeeAdminAccount ([string]$context.Account)
                $script:SetupStage = 'inspecting existing Entra application grants'
                $objectId = Get-SiteSeeDirectoryApp $App
                $verified = @{ revision = $script:SetupRevision; tenant = $Tenant; application = $App; principal = $objectId; account = $adminAccount }
                $verified | ConvertTo-Json -Compress | Set-Content -LiteralPath $Receipt -Encoding UTF8
                Write-Host 'Directory identity and existing-grant review: PASS'
            }
            'Exchange' {
                $script:SetupStage = 'validating the directory handoff'
                $verified = Read-SiteSeeReceipt $Receipt $Tenant $App
                $objectId = $verified.Principal
                Import-SiteSeePackageManagers
                $script:SetupStage = 'loading Exchange Online Management'
                Import-Module ExchangeOnlineManagement -MinimumVersion 3.7.0
                $script:SetupStage = 'Microsoft Exchange administrator sign-in'
                Write-Host "Exchange administrator account from the verified directory sign-in: $($verified.Account)"
                Connect-ExchangeOnline -UserPrincipalName $verified.Account -ShowBanner:$false | Out-Null
                $exchangeConnected = $true
                $script:SetupStage = 'checking available Exchange administration commands'
                foreach ($command in @('Get-ConnectionInformation', 'Get-OrganizationConfig', 'Get-EXOMailbox', 'Get-Recipient', 'Get-ServicePrincipal',
                    'New-ServicePrincipal', 'Get-ManagementScope', 'New-ManagementScope', 'Remove-ManagementScope',
                    'Get-ManagementRoleAssignment', 'New-ManagementRoleAssignment', 'Remove-ManagementRoleAssignment',
                    'Test-ServicePrincipalAuthorization')) { Get-Command $command -ErrorAction Stop | Out-Null }
                $script:SetupStage = 'configuring and verifying mailbox-scoped calendar authorization'
                Invoke-SiteSeeCalendarGrant $Tenant $App $objectId
            }
        }
        return 0
    } catch {
        Write-Host ''
        Write-Host 'FINAL RESULTS' -ForegroundColor Red
        Write-Host 'Microsoft calendar setup: STOPPED; do not activate migration.'
        Write-Host "Revision: $script:SetupRevision | Phase: $RunPhase"
        Write-Host "Stage: $script:SetupStage"
        $invocation = Get-SiteSeeValue $_ 'InvocationInfo'
        $failedCommand = Get-SiteSeeValue (Get-SiteSeeValue $invocation 'MyCommand') 'Name'
        Write-Host "Command: $failedCommand | Line: $(Get-SiteSeeValue $invocation 'ScriptLineNumber')"
        Write-Host "Error ID: $($_.FullyQualifiedErrorId)"
        Write-Host ([string]$_.Exception.Message)
        foreach ($line in $script:ExchangeIdentitySummary) { Write-Host $line }
        Write-Host 'Send this FINAL RESULTS block and any preceding STOP/REVIEW REQUIRED lines for review. No keys are needed.'
        return 1
    } finally {
        if ($graphConnected) { try { Disconnect-MgGraph | Out-Null } catch {} }
        if ($exchangeConnected) { try { Disconnect-ExchangeOnline -Confirm:$false -ErrorAction Stop | Out-Null } catch {} }
        if ($temporaryReceipt) { Remove-Item -LiteralPath $temporaryReceipt -Force -ErrorAction SilentlyContinue }
    }
}

# Dot-sourcing loads functions for offline tests; normal invocation performs setup.
if ($MyInvocation.InvocationName -ne '.') {
    $result = Start-SiteSeeSetup $TenantId $ApplicationId $Phase $ReceiptPath
    exit $result
}
