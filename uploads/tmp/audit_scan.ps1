$inc = 'c:\xampp\htdocs\gestion-dossiers-new\includes'
$names = @('chat.php','mailer.php','notifications.php','SecurityDashboard.php','security.php','csrf.php','functions.php','auth.php','chat_widget.php','chat_api_handler.php','xlsx.php','header.php','footer.php')
foreach ($n in $names) {
  $p = Join-Path $inc $n
  if (-not (Test-Path $p)) { continue }
  $lines = Get-Content $p -Encoding UTF8
  $depth = 0
  $hits = @()
  foreach ($l in $lines) {
    $opens = ([regex]::Matches($l, '\{')).Count
    $closes = ([regex]::Matches($l, '\}')).Count
    if ($depth -eq 0 -and $l -match '^\s*\$[a-zA-Z_].*->(query|prepare|exec)\(') {
      $t = $l.Trim(); if ($t.Length -gt 90) { $t = $t.Substring(0,90) }
      $hits += $t
    }
    $depth += $opens - $closes
  }
  if ($hits) { '== ' + $n; $hits | ForEach-Object { '   TOPLEVEL-QUERY: ' + $_ } }
  else { '== ' + $n + ' : clean' }
}
