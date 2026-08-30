param(
    [Parameter(Mandatory = $true)]
    [string]$ApiBaseUrl,

    [Parameter(Mandatory = $true)]
    [string]$ApiKey,

    [string]$SerialPort = "COM3",
    [ValidateRange(1200, 921600)]
    [int]$BaudRate = 9600,
    [ValidateRange(1, 60)]
    [int]$PollSeconds = 2,
    [switch]$Once,
    [switch]$DryRun
)

$ErrorActionPreference = "Stop"
$ApiBaseUrl = $ApiBaseUrl.TrimEnd("/")
$agentDirectory = Join-Path $env:LOCALAPPDATA "PrintFlowReceiptAgent"
$statePath = Join-Path $agentDirectory "printed-jobs.json"
$logPath = Join-Path $agentDirectory "agent.log"
New-Item -ItemType Directory -Path $agentDirectory -Force | Out-Null

function Write-AgentLog {
    param([string]$Message)
    $line = "{0} {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Message
    Add-Content -LiteralPath $logPath -Value $line -Encoding UTF8
}

function Get-PrintedJobs {
    if (-not (Test-Path -LiteralPath $statePath)) {
        return [System.Collections.Generic.HashSet[string]]::new()
    }

    try {
        $items = @(Get-Content -Raw -LiteralPath $statePath | ConvertFrom-Json)
        return [System.Collections.Generic.HashSet[string]]::new([string[]]$items)
    } catch {
        Write-AgentLog "Could not read printed-job state; starting with an empty cache."
        return [System.Collections.Generic.HashSet[string]]::new()
    }
}

function Save-PrintedJobs {
    param([System.Collections.Generic.HashSet[string]]$Jobs)
    $items = @($Jobs | Select-Object -Last 500)
    $temporaryPath = "$statePath.tmp"
    $items | ConvertTo-Json | Set-Content -LiteralPath $temporaryPath -Encoding UTF8
    Move-Item -LiteralPath $temporaryPath -Destination $statePath -Force
}

function Invoke-PrinterApi {
    param(
        [ValidateSet("GET", "POST")]
        [string]$Method,
        [string]$Action,
        [hashtable]$Body
    )

    $headers = @{ Authorization = "Bearer $ApiKey" }
    $uri = "$ApiBaseUrl/public/api/printer/jobs.php?action=$Action"
    if ($Method -eq "GET") {
        return Invoke-RestMethod -Uri $uri -Method Get -Headers $headers -TimeoutSec 20
    }

    return Invoke-RestMethod -Uri $uri -Method Post -Headers $headers `
        -ContentType "application/json" -Body ($Body | ConvertTo-Json -Compress) -TimeoutSec 20
}

function Write-EscPosSerial {
    param([byte[]]$Data)

    if ($DryRun) {
        Write-AgentLog "Dry run: skipped writing $($Data.Length) bytes to $SerialPort."
        return
    }

    $port = [System.IO.Ports.SerialPort]::new($SerialPort, $BaudRate, "None", 8, "One")
    $port.Handshake = "None"
    $port.WriteTimeout = 15000
    try {
        $port.Open()
        $port.Write($Data, 0, $Data.Length)
        $port.BaseStream.Flush()
        Start-Sleep -Milliseconds 300
    } finally {
        if ($port.IsOpen) {
            $port.Close()
        }
        $port.Dispose()
    }
}

$printedJobs = Get-PrintedJobs
Write-AgentLog "Agent started for $SerialPort at $BaudRate baud."

do {
    $job = $null
    try {
        $response = Invoke-PrinterApi -Method GET -Action "poll"
        $job = $response.job
        if ($null -eq $job) {
            if (-not $Once) {
                Start-Sleep -Seconds $PollSeconds
            }
            continue
        }

        $jobId = [string]$job.id
        if ($printedJobs.Contains($jobId)) {
            Invoke-PrinterApi -Method POST -Action "ack" -Body @{
                job_id = [int]$job.id
                status = "printed"
                message = "Previously printed by this agent; duplicate output suppressed."
            } | Out-Null
            Write-AgentLog "Job $jobId was already printed; acknowledgement restored."
            continue
        }

        $bytes = [Convert]::FromBase64String([string]$job.escpos_base64)
        Write-EscPosSerial -Data $bytes
        [void]$printedJobs.Add($jobId)
        Save-PrintedJobs -Jobs $printedJobs

        Invoke-PrinterApi -Method POST -Action "ack" -Body @{
            job_id = [int]$job.id
            status = "printed"
            message = if ($DryRun) { "Dry-run validation completed." } else { "Printed by Windows serial receipt agent." }
        } | Out-Null
        Write-AgentLog "Job $jobId completed ($($bytes.Length) bytes)."
    } catch {
        $message = $_.Exception.Message
        Write-AgentLog "Agent error: $message"
        if ($null -ne $job -and $null -ne $job.id) {
            try {
                Invoke-PrinterApi -Method POST -Action "ack" -Body @{
                    job_id = [int]$job.id
                    status = "failed"
                    message = $message
                } | Out-Null
            } catch {
                Write-AgentLog "Could not report failed job $($job.id): $($_.Exception.Message)"
            }
        }
        if (-not $Once) {
            Start-Sleep -Seconds $PollSeconds
        }
    }
} while (-not $Once)
