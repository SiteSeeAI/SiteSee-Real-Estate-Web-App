#requires -Version 5.1
<#
SiteSee: add mailbox-scoped Microsoft calendar authorization to the EXISTING app.
Run in a normal Windows PowerShell window. No client secret is requested.
Only Exchange Application RBAC objects are created. Existing grants are retained.
No Graph calendar/event/mail write, booking change, or migration is performed.
#>
[CmdletBinding()]
param([string]$TenantId, [string]$ApplicationId)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

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
        $org = Get-OrganizationConfig
        if ([string](Get-SiteSeeValue $org 'ExternalDirectoryOrganizationId') -ne $ExpectedTenant) {
            throw 'The Exchange sign-in belongs to a different tenant. No permission was changed.'
        }
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

function Initialize-SiteSeeModules {
    [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
    $gallery = Get-PSRepository -Name PSGallery
    if ([string]$gallery.SourceLocation -notmatch '^https://www\.powershellgallery\.com/api/v2/?$') {
        throw 'PSGallery is not pointing to the official PowerShell Gallery.'
    }
    if (-not @(Get-PackageProvider -ListAvailable | Where-Object { $_.Name -eq 'NuGet' -and $_.Version -ge [version]'2.8.5.201' }).Count) {
        Install-PackageProvider -Name NuGet -MinimumVersion 2.8.5.201 -Scope CurrentUser -Force | Out-Null
    }
    foreach ($module in @(
        @{ Name = 'Microsoft.Graph.Authentication'; Minimum = '2.0.0' },
        @{ Name = 'ExchangeOnlineManagement'; Minimum = '3.7.0' }
    )) {
        if (-not @(Get-Module -ListAvailable -Name $module.Name | Where-Object { $_.Version -ge [version]$module.Minimum }).Count) {
            Write-Host "Installing $($module.Name) for your Windows account..."
            Install-Module -Name $module.Name -MinimumVersion $module.Minimum -Repository PSGallery -Scope CurrentUser -Force
        }
    }
}

function Start-SiteSeeSetup([string]$Tenant, [string]$App) {
    $graphConnected = $false
    $exchangeConnected = $false
    try {
        Write-Host 'SiteSee Microsoft calendar access setup'
        Write-Host 'Uses the existing application. Enter IDs only; no secret or API key is needed.'
        if (-not $Tenant) { $Tenant = (Read-Host 'Tenant ID from WHM').Trim() }
        if (-not $App) { $App = (Read-Host 'Application ID from WHM').Trim() }
        $Tenant = ConvertTo-SiteSeeGuid $Tenant 'Tenant ID'
        $App = ConvertTo-SiteSeeGuid $App 'Application ID'
        Initialize-SiteSeeModules
        Import-Module Microsoft.Graph.Authentication -MinimumVersion 2.0.0
        Write-Host 'Sign in with your Microsoft 365 administrator. The directory check requests read-only Application.Read.All.'
        Connect-MgGraph -TenantId $Tenant -Scopes 'Application.Read.All' -ContextScope Process -NoWelcome | Out-Null
        $graphConnected = $true
        if ([string](Get-MgContext).TenantId -ne $Tenant) { throw 'The directory sign-in belongs to a different tenant.' }
        $objectId = Get-SiteSeeDirectoryApp $App
        Disconnect-MgGraph | Out-Null
        $graphConnected = $false
        Import-Module ExchangeOnlineManagement -MinimumVersion 3.7.0
        Write-Host 'Sign in to Exchange with the same Microsoft 365 administrator (Exchange role management is required).'
        Connect-ExchangeOnline -ShowBanner:$false | Out-Null
        $exchangeConnected = $true
        Invoke-SiteSeeCalendarGrant $Tenant $App $objectId
        return 0
    } catch {
        Write-Host ''
        Write-Host 'FINAL RESULTS' -ForegroundColor Red
        Write-Host 'Microsoft calendar setup: STOPPED; do not activate migration.'
        Write-Host ([string]$_.Exception.Message)
        Write-Host 'Send this FINAL RESULTS block and any preceding STOP/REVIEW REQUIRED lines for review. No keys are needed.'
        return 1
    } finally {
        if ($graphConnected) { try { Disconnect-MgGraph | Out-Null } catch {} }
        if ($exchangeConnected) { try { Disconnect-ExchangeOnline -Confirm:$false -ErrorAction Stop | Out-Null } catch {} }
    }
}

# Dot-sourcing loads functions for offline tests; normal invocation performs setup.
if ($MyInvocation.InvocationName -ne '.') {
    $result = Start-SiteSeeSetup $TenantId $ApplicationId
    exit $result
}
