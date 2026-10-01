<#
.SYNOPSIS
    Richtet Windows-Clients fuer die unbemerkte Windows-Anmeldung am Intranet ein.

.DESCRIPTION
    Edge, Chrome und der Internet Explorer senden die Windows-Anmeldung
    (Kerberos/NTLM) nur automatisch an Adressen der Zone "Lokales Intranet"
    bzw. der Richtlinie AuthServerAllowlist. Ein Hostname mit Punkten
    (z. B. intranet.firma.local) gilt ohne diese Freigabe als "Internet" -
    der Browser zeigt dann bei jedem Besuch einen Anmeldedialog.

    Das Skript schreibt die noetigen Richtlinien in HKLM (gilt fuer alle
    Benutzer des Geraets):
      - Zone "Lokales Intranet" fuer den Hostnamen (IE/Edge/Chrome ueber die
        Windows-Zonenzuordnung; Richtlinie "Liste der Site zu Zonenzuweisungen")
      - AuthServerAllowlist fuer Microsoft Edge und Google Chrome
      - optional Firefox (Richtlinien Authentication.SPNEGO / .NTLM)

    Alternativ dieselben Werte per Gruppenrichtlinie verteilen, siehe
    README "Windows-Anmeldung im Browser (SSO)".

.PARAMETER HostName
    Hostname des Intranets wie in APP_URL, z. B. intranet.firma.local.
    Mehrere Namen (Aliasse aus SSO_SPN_HOSTS) kommagetrennt.

.PARAMETER Firefox
    Zusaetzlich Firefox-Richtlinien setzen.

.PARAMETER Remove
    Die vom Skript gesetzten Eintraege wieder entfernen.

.EXAMPLE
    .\sso-client-setup.ps1 -HostName intranet.firma.local
    .\sso-client-setup.ps1 -HostName intranet.firma.local,intranet -Firefox

.NOTES
    Als Administrator ausfuehren. Danach den Browser neu starten.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string[]] $HostName,
    [switch] $Firefox,
    [switch] $Remove
)

$ErrorActionPreference = 'Stop'

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Bitte als Administrator ausfuehren.'
}

$hosts = @($HostName | ForEach-Object { $_ -split ',' } | ForEach-Object { $_.Trim().ToLowerInvariant() } | Where-Object { $_ -ne '' } | Select-Object -Unique)
foreach ($h in $hosts) {
    if ($h -notmatch '^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$') {
        throw "Ungueltiger Hostname: $h (nur Hostnamen, keine URLs oder IP-Adressen)."
    }
}

# Zone 1 = Lokales Intranet. Richtlinienpfad (gilt fuer IE, Edge und Chrome):
# "Liste der Site zu Zonenzuweisungen" unter Internet Explorer -> Internetsystemsteuerung -> Sicherheitsseite.
$zoneMapPolicy = 'HKLM:\SOFTWARE\Policies\Microsoft\Windows\CurrentVersion\Internet Settings\ZoneMap'
$edgePolicy = 'HKLM:\SOFTWARE\Policies\Microsoft\Edge'
$chromePolicy = 'HKLM:\SOFTWARE\Policies\Google\Chrome'
$firefoxPolicy = 'HKLM:\SOFTWARE\Policies\Mozilla\Firefox\Authentication'

function Set-ZoneMapping([string] $Name, [switch] $Delete) {
    $parts = $Name -split '\.'
    if ($parts.Count -ge 2) {
        $domain = ($parts | Select-Object -Last 2) -join '.'
        $sub = ($parts | Select-Object -SkipLast 2) -join '.'
    } else {
        $domain = $Name
        $sub = ''
    }
    $key = Join-Path $zoneMapPolicy "Domains\$domain"
    if ($sub -ne '') { $key = Join-Path $key $sub }

    if ($Delete) {
        if (Test-Path $key) {
            foreach ($scheme in 'http', 'https') { Remove-ItemProperty -Path $key -Name $scheme -ErrorAction SilentlyContinue }
            if (-not (Get-Item $key).Property) { Remove-Item $key -Force }
        }
        return
    }

    New-Item -Path $key -Force | Out-Null
    foreach ($scheme in 'http', 'https') { New-ItemProperty -Path $key -Name $scheme -PropertyType DWord -Value 1 -Force | Out-Null }
}

function Set-Allowlist([string] $Path, [string] $Name, [string[]] $Hosts, [switch] $Delete) {
    if ($Delete) {
        if (Test-Path $Path) {
            $current = (Get-ItemProperty -Path $Path -Name $Name -ErrorAction SilentlyContinue).$Name
            if ($null -ne $current) {
                $rest = @(($current -split ',') | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' -and $Hosts -notcontains $_.ToLowerInvariant() })
                if ($rest.Count -gt 0) {
                    Set-ItemProperty -Path $Path -Name $Name -Value ($rest -join ',')
                } else {
                    Remove-ItemProperty -Path $Path -Name $Name
                }
            }
        }
        return
    }

    New-Item -Path $Path -Force | Out-Null
    $current = (Get-ItemProperty -Path $Path -Name $Name -ErrorAction SilentlyContinue).$Name
    $merged = @()
    if ($current) { $merged += ($current -split ',') | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' } }
    $merged += $Hosts
    $merged = @($merged | Select-Object -Unique)
    New-ItemProperty -Path $Path -Name $Name -PropertyType String -Value ($merged -join ',') -Force | Out-Null
}

function Set-FirefoxAuth([string[]] $Hosts, [switch] $Delete) {
    foreach ($name in 'SPNEGO', 'NTLM') {
        $key = Join-Path $firefoxPolicy $name
        if ($Delete) {
            if (Test-Path $key) {
                foreach ($prop in (Get-Item $key).Property) {
                    $value = (Get-ItemProperty -Path $key -Name $prop).$prop
                    if ($Hosts -contains ([string] $value).ToLowerInvariant()) { Remove-ItemProperty -Path $key -Name $prop }
                }
                if (-not (Get-Item $key).Property) { Remove-Item $key -Force }
            }
            continue
        }

        New-Item -Path $key -Force | Out-Null
        $existing = @()
        foreach ($prop in (Get-Item $key).Property) { $existing += ([string] (Get-ItemProperty -Path $key -Name $prop).$prop).ToLowerInvariant() }
        $index = $existing.Count + 1
        foreach ($h in $Hosts) {
            if ($existing -contains $h) { continue }
            New-ItemProperty -Path $key -Name ([string] $index) -PropertyType String -Value $h -Force | Out-Null
            $index++
        }
    }
}

foreach ($h in $hosts) { Set-ZoneMapping -Name $h -Delete:$Remove }
Set-Allowlist -Path $edgePolicy -Name 'AuthServerAllowlist' -Hosts $hosts -Delete:$Remove
Set-Allowlist -Path $chromePolicy -Name 'AuthServerAllowlist' -Hosts $hosts -Delete:$Remove
if ($Firefox) { Set-FirefoxAuth -Hosts $hosts -Delete:$Remove }

if ($Remove) {
    Write-Host "Eintraege fuer $($hosts -join ', ') entfernt."
} else {
    Write-Host "Unbemerkte Windows-Anmeldung freigegeben fuer: $($hosts -join ', ')"
    Write-Host 'Zone "Lokales Intranet" (IE/Edge/Chrome) und AuthServerAllowlist (Edge/Chrome) gesetzt.'
    if ($Firefox) { Write-Host 'Firefox: Authentication.SPNEGO und Authentication.NTLM gesetzt.' }
    Write-Host 'Browser neu starten; Pruefung: Seite oeffnen - der Benutzer erscheint ohne Dialog im Kopf.'
}
