' Launches `php artisan schedule:run` with its console window fully hidden.
' Task Scheduler flashing a visible cmd window every minute (running
' php.exe directly as the task action) is what this works around - WScript
' can launch a process with window style 0 (hidden), which a bare
' Scheduled Task action pointed at php.exe cannot do on its own.
Set objShell = CreateObject("WScript.Shell")
objShell.CurrentDirectory = "C:\xampp\htdocs\OzPAX"
objShell.Run "C:\xampp\php\php.exe artisan schedule:run", 0, True
