@echo off
setlocal enabledelayedexpansion

echo.
echo ========================================================
echo          SILENT GUARDIAN AUTO-START SETUP
echo ========================================================
echo.
echo 🛡️ SISTEM MONITORING MURNI - TIDAK MENGUBAH DATA APAPUN
echo.
echo Sistem ini akan:
echo   ✅ HANYA monitoring dan alert
echo   ✅ TIDAK mengubah rekaman stok lama
echo   ✅ TIDAK mengubah data transaksi lama
echo   ✅ TIDAK memengaruhi transaksi hari ini
echo   ✅ TIDAK memengaruhi transaksi kedepannya
echo   ✅ Berjalan 100%% otomatis tanpa intervensi user
echo.

pause

echo.
echo 🚀 Memulai setup Silent Guardian...
echo.

REM Set working directory
set "APOTEK_DIR=C:\laragon\www\apotekbisma\apotekbisma"

if not exist "%APOTEK_DIR%" (
    echo ❌ Error: Directory apotek tidak ditemukan!
    echo    Expected: %APOTEK_DIR%
    pause
    exit /b 1
)

cd /d "%APOTEK_DIR%"

echo ✅ Working directory: %APOTEK_DIR%
echo.

REM 1. Create Windows startup script
echo 📁 Creating Windows startup integration...

set "STARTUP_SCRIPT=%USERPROFILE%\AppData\Roaming\Microsoft\Windows\Start Menu\Programs\Startup\SilentGuardian.bat"

(
echo @echo off
echo setlocal
echo.
echo REM Wait for system to fully boot
echo timeout /t 120 /nobreak ^>nul
echo.
echo REM Check if XAMPP is running
echo :wait_xampp
echo tasklist /FI "IMAGENAME eq httpd.exe" 2^>nul ^| find "httpd.exe" ^>nul
echo if errorlevel 1 ^(
echo     timeout /t 30 /nobreak ^>nul
echo     goto wait_xampp
echo ^)
echo.
echo REM Start Silent Guardian
echo cd /d "%APOTEK_DIR%"
echo start /min "Silent Guardian" php silent_guardian_service.php start
echo.
echo exit /b 0
) > "%STARTUP_SCRIPT%"

if exist "%STARTUP_SCRIPT%" (
    echo ✅ Windows startup script created
) else (
    echo ❌ Failed to create startup script
)

REM 2. Create XAMPP integration script
echo 📁 Creating XAMPP integration...

(
echo @echo off
echo setlocal
echo.
echo REM Start Silent Guardian when XAMPP starts
echo cd /d "%APOTEK_DIR%"
echo.
echo REM Check if already running
echo if exist "storage\logs\silent_guardian.pid" ^(
echo     echo Silent Guardian already running
echo     exit /b 0
echo ^)
echo.
echo REM Start service
echo start /min "Silent Guardian" php silent_guardian_service.php start
echo.
echo echo Silent Guardian started with XAMPP
echo exit /b 0
) > "start_silent_guardian_with_xampp.bat"

echo ✅ XAMPP integration script created

REM 3. Create desktop dashboard
echo 📁 Creating desktop dashboard...

set "DESKTOP_DASHBOARD=%USERPROFILE%\Desktop\Silent Guardian Dashboard.bat"

(
echo @echo off
echo setlocal enabledelayedexpansion
echo.
echo echo.
echo echo ========================================
echo echo      SILENT GUARDIAN DASHBOARD
echo echo ========================================
echo echo.
echo echo 🛡️ Pure Monitoring Mode - NO DATA CHANGES
echo echo.
echo.
echo :menu
echo echo Choose an option:
echo echo.
echo echo [1] Check Guardian Status
echo echo [2] Start Guardian Service  
echo echo [3] Stop Guardian Service
echo echo [4] Restart Guardian Service
echo echo [5] View Monitoring Logs
echo echo [6] View Alert Logs
echo echo [7] System Health Check
echo echo [0] Exit
echo echo.
echo.
echo set /p choice="Enter your choice (0-7): "
echo.
echo if "%%choice%%"=="1" goto check_status
echo if "%%choice%%"=="2" goto start_service
echo if "%%choice%%"=="3" goto stop_service
echo if "%%choice%%"=="4" goto restart_service
echo if "%%choice%%"=="5" goto view_logs
echo if "%%choice%%"=="6" goto view_alerts
echo if "%%choice%%"=="7" goto health_check
echo if "%%choice%%"=="0" goto exit
echo.
echo echo Invalid choice! Please try again.
echo echo.
echo goto menu
echo.
echo :check_status
echo echo.
echo echo 🔍 Checking Guardian Status...
echo echo =====================================
echo cd /d "%APOTEK_DIR%"
echo php silent_guardian_service.php status
echo echo.
echo pause
echo goto menu
echo.
echo :start_service
echo echo.
echo echo 🚀 Starting Guardian Service...
echo echo =====================================
echo cd /d "%APOTEK_DIR%"
echo start /min "Silent Guardian" php silent_guardian_service.php start
echo echo Service started in background
echo echo.
echo pause
echo goto menu
echo.
echo :stop_service
echo echo.
echo echo 🛑 Stopping Guardian Service...
echo echo =====================================
echo cd /d "%APOTEK_DIR%"
echo php silent_guardian_service.php stop
echo echo.
echo pause
echo goto menu
echo.
echo :restart_service
echo echo.
echo echo 🔄 Restarting Guardian Service...
echo echo =====================================
echo cd /d "%APOTEK_DIR%"
echo php silent_guardian_service.php stop
echo timeout /t 3 /nobreak ^>nul
echo start /min "Silent Guardian" php silent_guardian_service.php start
echo echo Service restarted
echo echo.
echo pause
echo goto menu
echo.
echo :view_logs
echo echo.
echo echo 📝 Viewing Monitoring Logs...
echo echo =====================================
echo cd /d "%APOTEK_DIR%"
echo if exist "storage\logs\silent_guardian.log" ^(
echo     echo Recent monitoring entries:
echo     echo.
echo     powershell "Get-Content 'storage\logs\silent_guardian.log' ^| Select-Object -Last 20"
echo ^) else ^(
echo     echo No monitoring log file found.
echo ^)
echo echo.
echo pause
echo goto menu
echo.
echo :view_alerts
echo echo.
echo echo 🚨 Viewing Alert Logs...
echo echo =====================================
echo cd /d "%APOTEK_DIR%"
echo if exist "storage\logs\silent_guardian_alerts.log" ^(
echo     echo Recent alerts:
echo     echo.
echo     powershell "Get-Content 'storage\logs\silent_guardian_alerts.log' ^| Select-Object -Last 10"
echo ^) else ^(
echo     echo No alerts found - system is healthy!
echo ^)
echo echo.
echo pause
echo goto menu
echo.
echo :health_check
echo echo.
echo echo 💚 System Health Check...
echo echo =====================================
echo echo Current Time: %%date%% %%time%%
echo echo Working Directory: %%cd%%
echo.
echo echo 🔍 Checking XAMPP Status...
echo tasklist /FI "IMAGENAME eq httpd.exe" 2^>nul ^| find "httpd.exe" ^>nul
echo if errorlevel 1 ^(
echo     echo ❌ Apache is not running
echo ^) else ^(
echo     echo ✅ Apache is running
echo ^)
echo.
echo tasklist /FI "IMAGENAME eq mysqld.exe" 2^>nul ^| find "mysqld.exe" ^>nul
echo if errorlevel 1 ^(
echo     echo ❌ MySQL is not running
echo ^) else ^(
echo     echo ✅ MySQL is running
echo ^)
echo.
echo echo 🔍 Checking Guardian Service...
echo cd /d "%APOTEK_DIR%"
echo php silent_guardian_service.php status
echo.
echo pause
echo goto menu
echo.
echo :exit
echo echo.
echo echo 👋 Dashboard closed. Silent Guardian continues monitoring in background.
echo echo.
echo exit /b 0
) > "%DESKTOP_DASHBOARD%"

if exist "%DESKTOP_DASHBOARD%" (
    echo ✅ Desktop dashboard created
) else (
    echo ❌ Failed to create desktop dashboard
)

REM 4. Test service
echo.
echo 🧪 Testing Silent Guardian service...
php silent_guardian_service.php status

echo.
echo.
echo ========================================================
echo                  SETUP COMPLETED!
echo ========================================================
echo.
echo 🎉 Silent Guardian Auto-Start telah dikonfigurasi!
echo.
echo 📋 Yang telah disetup:
echo   ✅ Windows startup integration
echo   ✅ XAMPP integration script  
echo   ✅ Desktop dashboard
echo   ✅ Pure monitoring service (NO DATA CHANGES)
echo.
echo 🚀 Cara kerja:
echo   1. Laptop dinyalakan → Windows startup trigger
echo   2. Wait 2 menit → Tunggu XAMPP fully loaded
echo   3. Start Silent Guardian → Monitoring service aktif
echo   4. Monitor setiap 30 menit → Pure monitoring only
echo   5. Alert jika ada masalah → Notifikasi tanpa mengubah data
echo.
echo 🛡️ JAMINAN:
echo   ✅ TIDAK akan mengubah rekaman stok lama
echo   ✅ TIDAK akan mengubah data transaksi lama
echo   ✅ TIDAK akan memengaruhi transaksi hari ini
echo   ✅ TIDAK akan memengaruhi transaksi kedepannya
echo   ✅ HANYA monitoring dan alert
echo.
echo 💡 Control:
echo   - Desktop shortcut: "Silent Guardian Dashboard"
echo   - 100%% otomatis, tidak perlu intervensi user
echo.
echo 🔄 Auto-start akan aktif setelah restart laptop
echo.

pause
