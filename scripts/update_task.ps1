# Rattrapage : si le PC est eteint a 21:00, la sauvegarde s'execute des que le PC
# redemarre (StartWhenAvailable), meme plusieurs heures/jours plus tard.
$action = New-ScheduledTaskAction -Execute 'c:\xampp\htdocs\gestion-dossiers-new\scripts\backup_daily_task.bat'
$trigger = New-ScheduledTaskTrigger -Daily -At 21:00
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -DontStopIfGoingOnBatteries -AllowStartIfOnBatteries -ExecutionTimeLimit (New-TimeSpan -Hours 2)
Set-ScheduledTask -TaskName 'GestionDossiers Backup Quotidien' -Action $action -Trigger $trigger -Settings $settings | Out-Null
Write-Output 'Task updated with StartWhenAvailable.'
Get-ScheduledTask -TaskName 'GestionDossiers Backup Quotidien' | Select-Object TaskName, State
