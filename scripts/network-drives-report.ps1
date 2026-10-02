<#
.SYNOPSIS
    Meldet die gemappten Netzlaufwerke des angemeldeten Benutzers an das Intranet.

.DESCRIPTION
    Nextcloud (Office im Intranet) kann die Netzlaufwerke eines Windows-Clients
    nicht selbst sehen. Dieses Skript laeuft bei der Windows-Anmeldung im
    Benutzerkontext (Gruppenrichtlinie: Benutzerkonfiguration > Richtlinien >
    Windows-Einstellungen > Skripts > Anmelden > PowerShell-Skripts) und meldet
    alle gemappten Netzlaufwerke (Buchstabe -> UNC-Pfad) per Windows-Anmeldung
    (Kerberos/NTLM, kein Kennwort) an das Intranet. Jede Meldung ersetzt den
    bisherigen Stand des Benutzers.

    Im Intranet ausgeschlossene Laufwerke (Admin > Netzlaufwerke, Standard B:
    und G:) werden nie an Nextcloud weitergereicht. Eingebunden werden die
    Laufwerke nur fuer Benutzer, die in Nextcloud unter "Dateien" >
    Einstellungen "Netzlaufwerke anzeigen" aktiviert haben.

    Protokoll: %LOCALAPPDATA%\Intranet\netzlaufwerke.log

.PARAMETER Url
    Meldeadresse des Intranets (APP_URL + /sso/laufwerke). Beim Herunterladen
    aus dem Adminbereich bereits eingetragen.

.PARAMETER DelaySeconds
    Wartezeit vor der Meldung, damit per Gruppenrichtlinie verbundene
    Laufwerke bereits vorhanden sind (Standard 20 Sekunden).

.EXAMPLE
    powershell.exe -NoProfile -ExecutionPolicy Bypass -File netzlaufwerke-melden.ps1
#>
[CmdletBinding()]
param(
    [string] $Url = 'https://intranet.example.internal/sso/laufwerke',
    [ValidateRange(0, 600)]
    [int] $DelaySeconds = 20,
    [ValidateRange(1, 10)]
    [int] $Attempts = 3
)

$ErrorActionPreference = 'Stop'

$logDir = Join-Path $env:LOCALAPPDATA 'Intranet'
$logFile = Join-Path $logDir 'netzlaufwerke.log'

function Write-Log([string] $Message) {
    try {
        if (-not (Test-Path $logDir)) { New-Item -ItemType Directory -Path $logDir -Force | Out-Null }
        $line = '{0:yyyy-MM-dd HH:mm:ss} {1}' -f (Get-Date), $Message
        $existing = @()
        if (Test-Path $logFile) { $existing = @(Get-Content -Path $logFile -Tail 300 -ErrorAction SilentlyContinue) }
        Set-Content -Path $logFile -Value ($existing + $line) -Encoding UTF8
    } catch {
        # Protokoll ist optional.
    }
}

function Get-MappedDrives {
    $drives = [ordered]@{}

    # Verbundene Netzlaufwerke der aktuellen Sitzung.
    try {
        foreach ($disk in @(Get-CimInstance -ClassName Win32_LogicalDisk -Filter 'DriveType = 4')) {
            $letter = ([string] $disk.DeviceID).TrimEnd(':').ToUpperInvariant()
            $path = [string] $disk.ProviderName
            if ($letter -match '^[A-Z]$' -and $path.StartsWith('\\')) { $drives[$letter] = $path }
        }
    } catch {
        Write-Log "Hinweis: Win32_LogicalDisk nicht abfragbar ($($_.Exception.Message))."
    }

    # Dauerhafte Zuordnungen, die (noch) nicht verbunden sind.
    try {
        foreach ($key in @(Get-ChildItem -Path 'HKCU:\Network' -ErrorAction SilentlyContinue)) {
            $letter = ([string] $key.PSChildName).ToUpperInvariant()
            $path = [string] (Get-ItemProperty -Path $key.PSPath -Name RemotePath -ErrorAction SilentlyContinue).RemotePath
            if ($letter -match '^[A-Z]$' -and $path.StartsWith('\\') -and -not $drives.Contains($letter)) { $drives[$letter] = $path }
        }
    } catch {
        # Ohne dauerhafte Zuordnungen nichts zu tun.
    }

    return $drives
}

try {
    if ($DelaySeconds -gt 0) { Start-Sleep -Seconds $DelaySeconds }

    # Windows PowerShell 5.1: TLS 1.2 explizit erlauben.
    try { [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12 } catch { }

    $drives = Get-MappedDrives
    $lines = @($drives.Keys | Sort-Object | ForEach-Object { '{0}={1}' -f $_, $drives[$_] })
    $body = @{
        laufwerke = ($lines -join "`n")
        domaene = [string] $env:USERDOMAIN
        computer = [string] $env:COMPUTERNAME
    }

    for ($attempt = 1; $attempt -le $Attempts; $attempt++) {
        try {
            $response = Invoke-WebRequest -Uri $Url -Method Post -Body $body -Headers @{ 'X-Intranet-Client' = 'netzlaufwerke' } -UseDefaultCredentials -UseBasicParsing -TimeoutSec 60
            Write-Log ("Gemeldet ({0} Laufwerk(e)): {1}" -f $lines.Count, ([string] $response.Content).Trim())
            exit 0
        } catch {
            $status = $null
            if ($_.Exception.Response) { $status = [int] $_.Exception.Response.StatusCode }
            Write-Log ("Versuch {0}/{1} fehlgeschlagen{2}: {3}" -f $attempt, $Attempts, $(if ($status) { " (HTTP $status)" } else { '' }), $_.Exception.Message)
            # 400/401/403/404: Anmeldung bzw. Konfiguration - Wiederholen hilft nicht.
            if ($status -in 400, 401, 403, 404) { break }
            if ($attempt -lt $Attempts) { Start-Sleep -Seconds (15 * $attempt) }
        }
    }
} catch {
    Write-Log "Fehler: $($_.Exception.Message)"
}

# Die Windows-Anmeldung darf nie an diesem Skript scheitern.
exit 0
