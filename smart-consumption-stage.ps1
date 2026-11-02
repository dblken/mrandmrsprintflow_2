param(
    [string]$SshTarget = 'u618446170@145.79.26.174'
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

# Source only the verified isolated package; never read from the dirty checkout.
$Package = 'C:\Users\Arron Tuazon\.codex\visualizations\2026\10\20\01a1575d-4c5a-79f0-99ec-dc3f750c4973\material-smart-consumption-release-package-final-20261027'
$Port = 65002
$ExpectedHome = '/home/u618446170'
$RemoteParent = "$ExpectedHome/.printflow-smart-consumption-staging"
$Release = '38b423f263f17df8a150418c323b2421644aa961'

$Files = @(
    @{ Path='admin/job_orders_api.php'; SHA256='11fbf6c63c1ff7ebb6fa836c593a4bdbc5c383ef91a22aa975b28d69c3367a61' },
    @{ Path='includes/JobOrderService.php'; SHA256='01a08ba004b5f419731254fa93dfc8d3b5b10943de398e819a45a03f0c4e3b6b' },
    @{ Path='includes/production_material_compatibility.php'; SHA256='b5e8ce0a8e0c34befa5e1e03e9bfc25b0f8113d260aad78fd49977c87d910d69' },
    @{ Path='includes/inventory_material_usage_confirmation.php'; SHA256='b60629c67afb3d18445fd9a151a783bb2a957ead03efe5cc1928e93616d71608' },
    @{ Path='public/assets/js/material_consumption_estimator.js'; SHA256='34c260dda931e86a1bd27d97e252ff4079af11fe521480ceac49bd5e4c170794' },
    @{ Path='public/assets/js/production_material_picker.js'; SHA256='6dac788ff08fa42f7e082cbc1d91fb1b627a021385d0e0d323cf9a9591c42365' },
    @{ Path='staff/customizations.php'; SHA256='621a422002c18353b0f2a7b4c39a152e3c324f32f02bf58bef13deb0b58e6a7c' }
)

if ($SshTarget -notmatch '^[A-Za-z0-9_.@:-]+$') { throw 'SshTarget must be one SSH alias or user@host token.' }
if (-not (Test-Path -LiteralPath $Package -PathType Container)) { throw "Verified package is missing: $Package" }
$ssh = (Get-Command ssh.exe -ErrorAction Stop).Source
$scp = (Get-Command scp.exe -ErrorAction Stop).Source
$tar = (Get-Command tar.exe -ErrorAction Stop).Source

$expectedPaths = @($Files | ForEach-Object { $_.Path } | Sort-Object)
$packageFiles = @(Get-ChildItem -LiteralPath $Package -Recurse -File)
$actualPaths = @($packageFiles | ForEach-Object { $_.FullName.Substring($Package.Length + 1).Replace('\','/') } | Sort-Object)
if (($actualPaths -join '|') -ne ($expectedPaths -join '|')) {
    throw 'Package tree does not contain exactly the seven approved runtime files; no transfer attempted.'
}
foreach ($file in $Files) {
    $localPath = Join-Path $Package ($file.Path.Replace('/','\'))
    $hash = (Get-FileHash -LiteralPath $localPath -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($hash -ne $file.SHA256) { throw "Candidate SHA-256 mismatch: $($file.Path); no transfer attempted." }
    Write-Output "LOCAL_PACKAGE_PASS $($file.Path) sha256=$hash"
}

$stamp = [DateTime]::UtcNow.ToString('yyyyMMddTHHmmssZ')
$runId = [Guid]::NewGuid().ToString('N')
$RemoteStage = "$RemoteParent/candidate-$Release-$stamp-$runId"
$RemoteArchive = "$RemoteParent/upload-$runId.tar"
if ($RemoteStage -notmatch '^/home/u618446170/\.printflow-smart-consumption-staging/candidate-[A-Za-z0-9-]+$' -or
    $RemoteArchive -notmatch '^/home/u618446170/\.printflow-smart-consumption-staging/upload-[a-f0-9]{32}\.tar$') {
    throw 'Generated remote paths failed the safety allowlist; no transfer attempted.'
}

$archiveLocal = Join-Path ([IO.Path]::GetTempPath()) "smart-consumption-$runId.tar"
try {
    # Tar contains exactly seven manifest paths; the remote parent is mode 0700.
    $tarArgs = @('-cf', $archiveLocal, '-C', $Package, '--') + $expectedPaths
    & $tar @tarArgs
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $archiveLocal -PathType Leaf)) { throw 'Could not create the local seven-file archive.' }

    # This is the only SSH setup connection. Failed attempts use unique paths and leave old partial stages untouched.
    $setup = 'set -eu; umask 077; test "$HOME" = "/home/u618446170"; test "$(cd "$HOME" && pwd -P)" = "/home/u618446170"; p="/home/u618446170/.printflow-smart-consumption-staging"; if [ -L "$p" ]; then exit 31; fi; if [ ! -e "$p" ]; then mkdir -m 700 -- "$p"; fi; test -d "$p"; test "$(stat -c "%a" -- "$p")" = 700; test "$(stat -c "%u" -- "$p")" = "$(id -u)"'
    & $ssh -p $Port -T $SshTarget $setup
    if ($LASTEXITCODE -ne 0) { throw 'Private staging parent check failed; production files were not touched.' }

    # One SCP session transfers the complete archive, rather than opening seven SCP sessions.
    & $scp -P $Port $archiveLocal "$($SshTarget):$RemoteArchive"
    if ($LASTEXITCODE -ne 0) { throw "Archive upload failed; any partial upload remains inside the private staging parent. No production files were touched." }

    $manifest = ($Files | ForEach-Object { "$($_.SHA256)  $($_.Path)" }) -join "`n"
    $expectedList = ($expectedPaths | ForEach-Object { "'$_'" }) -join ' '
    $quotedPackagePaths = ($expectedPaths | ForEach-Object { "'$_'" }) -join ' '
    $metadataChecks = ($Files | ForEach-Object {
        "p='$($_.Path)'; m=`$(stat -c '%a %u %g' -- `"`$s/`$p`"`) || exit 41; set -- `$m; [ `"`$#`" -eq 3 ] || exit 42; [ `"`$1`" = 600 ] || exit 43; [ `"`$2`" = `"`$uid`" ] || exit 44; case `"`$3`" in ''|*[!0-9]*) exit 45;; esac; printf 'STAGED_PASS %s sha256=%s mode=%s owner=%s:%s\n' '$($_.Path)' '$($_.SHA256)' `"`$1`" `"`$2`" `"`$3`""
    }) -join "`n"

    $remoteScript = @"
set -eu
umask 077
test "`$HOME" = "$ExpectedHome"
test "`$(cd "`$HOME" && pwd -P)" = "$ExpectedHome"
p='$RemoteParent'
s='$RemoteStage'
a='$RemoteArchive'
case "`$s" in "`$p"/candidate-*) ;; *) exit 32;; esac
case "`$a" in "`$p"/upload-*.tar) ;; *) exit 33;; esac
test -d "`$p" && test ! -L "`$p"
test "`$(stat -c '%a' -- "`$p")" = 700
test "`$(stat -c '%u' -- "`$p")" = "`$(id -u)"
test -f "`$a" && test ! -L "`$a"
mkdir -m 700 -- "`$s"
expected=`$(printf '%s\n' $expectedList | LC_ALL=C sort)
actual=`$(tar -tf "`$a" | LC_ALL=C sort)
test "`$actual" = "`$expected"
tar -xf "`$a" -C "`$s"
cd "`$s"
cat > .approved.sha256 <<'PRINTFLOW_MANIFEST'
$manifest
PRINTFLOW_MANIFEST
sha256sum -c .approved.sha256
uid=`$(id -u)
chmod 600 -- $quotedPackagePaths
$metadataChecks
rm -f -- .approved.sha256 "`$a"
printf 'STAGE_DIR=%s\n' "`$s"
printf 'STAGING_RESULT=PASS release=%s files=7 port=%s\n' '$Release' '$Port'
"@

    $encodedScript = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($remoteScript))
    $remoteCommand = "printf '%s' '$encodedScript' | base64 -d | sh"
    $remoteOutput = @(& $ssh -p $Port -T $SshTarget $remoteCommand)
    $sshExit = $LASTEXITCODE
    $remoteLines = @($remoteOutput | ForEach-Object { "$($_)" })
    $remoteLines | ForEach-Object { Write-Output $_ }
    if ($sshExit -ne 0) { throw "Remote extraction or staged verification failed (SSH exit $sshExit). The unique partial stage remains private; production files were not touched." }

    $passLines = @($remoteLines | Where-Object { $_ -match '^STAGED_PASS ' })
    if ($passLines.Count -ne 7 -or @($remoteLines | Where-Object { $_ -match '^STAGING_RESULT=PASS release=' }).Count -ne 1 -or
        @($remoteLines | Where-Object { $_ -eq "STAGE_DIR=$RemoteStage" }).Count -ne 1) {
        throw 'Remote verification output was incomplete or malformed; no staging success is reported.'
    }
    for ($i = 0; $i -lt $Files.Count; $i++) {
        if ($passLines[$i] -notmatch ('^STAGED_PASS ' + [regex]::Escape($Files[$i].Path) + ' sha256=' + $Files[$i].SHA256 + ' mode=600 owner=[0-9]+:[0-9]+$')) {
            throw "Remote success output did not match manifest entry $($Files[$i].Path); no staging success is reported."
        }
    }
}
finally {
    if (Test-Path -LiteralPath $archiveLocal -PathType Leaf) { Remove-Item -LiteralPath $archiveLocal -Force }
}
