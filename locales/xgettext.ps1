param (
    [string]$Exe,  # gettext path
    [string]$Output,  # Ziel-Datei für die Ausgabe (.pot)
	[string]$Domain  # gettext domain
)

# Aktuelles Verzeichnis
$BaseDir = Get-Location

# Ziel-Datei im TEMP-Ordner
$OutputFile1 = "$env:TEMP\xgettext-php.txt"
$OutputFile2 = "$env:TEMP\xgettext-twig.txt"

# Suche alle .php-Dateien und speichere relative Pfade
$files1 = Get-ChildItem -Path $BaseDir -Recurse -Filter "*.php"
$files2 = Get-ChildItem -Path $BaseDir -Recurse -Filter "*.twig"
$Content1 = $files1 | ForEach-Object { $_.FullName.Replace("$BaseDir\", "") }
$Content2 = $files2 | ForEach-Object { $_.FullName.Replace("$BaseDir\", "") }

[IO.File]::WriteAllLines($OutputFile1 , $Content1)
[IO.File]::WriteAllLines($OutputFile2 , $Content2)

if (Test-Path $Output)
{
	Remove-Item $Output -Force
}

Start-Process $Exe -ArgumentList "--from-code=UTF-8", "--language=PHP", "--keyword=_", "--keyword=__", "--no-wrap", "-D", ".\", "-d", $Domain, "-o", $Output, "-f", $OutputFile1 -Wait -WorkingDirectory $BaseDir -NoNewWindow

Start-Process $Exe -ArgumentList "--from-code=UTF-8", "--join-existing", "--language=C", "--keyword=_", "--keyword=__", "--no-wrap", "-D", ".\", "-d", $Domain, "-o", $Output, "-f", $OutputFile2 -Wait -WorkingDirectory $BaseDir -NoNewWindow
