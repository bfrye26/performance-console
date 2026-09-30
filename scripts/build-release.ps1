$ErrorActionPreference = 'Stop'
$pluginRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$pluginHeader = Get-Content (Join-Path $pluginRoot 'wp-performance-inspector.php') -Raw
if ($pluginHeader -notmatch "define\( 'WPI_VERSION', '([0-9.]+)' \)") { throw 'Plugin version not found.' }
$releaseVersion = $Matches[1]
$outputDir = Join-Path $pluginRoot 'dist'
New-Item -ItemType Directory -Path $outputDir -Force | Out-Null
$archivePath = Join-Path $outputDir "wp-performance-inspector-$releaseVersion.zip"
$releaseFiles = @('wp-performance-inspector.php', 'readme.txt', 'README.md', 'IMPROVEMENT-PLAN.md')
foreach ($runtimeDir in @('assets', 'includes', 'mu-plugin')) {
    $releaseFiles += Get-ChildItem -LiteralPath (Join-Path $pluginRoot $runtimeDir) -File -Recurse | ForEach-Object {
        $_.FullName.Substring($pluginRoot.Length + 1)
    }
}
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$archiveStream = [System.IO.File]::Open($archivePath, [System.IO.FileMode]::CreateNew)
$archive = New-Object System.IO.Compression.ZipArchive($archiveStream, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($relativePath in $releaseFiles) {
        $entryName = 'wp-performance-inspector/' + $relativePath.Replace('\', '/')
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, (Join-Path $pluginRoot $relativePath), $entryName) | Out-Null
    }
} finally {
    $archive.Dispose()
    $archiveStream.Dispose()
}
$archiveInfo = Get-Item -LiteralPath $archivePath
$checksum = Get-FileHash -LiteralPath $archivePath -Algorithm SHA256
Write-Output ('Archive: ' + $archiveInfo.FullName)
Write-Output ('Bytes: ' + $archiveInfo.Length)
Write-Output ('SHA256: ' + $checksum.Hash)
