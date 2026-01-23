@echo off
echo ========================================
echo QUICK VERIFICATION TEST - UPDATE STOK
echo ========================================
echo.

php test_stok_update_robust.php > nul 2>&1
if %errorlevel% equ 0 (
    echo [OK] Basic Robustness Test PASSED
) else (
    echo [FAIL] Basic Robustness Test FAILED
)

php test_stok_stress.php > nul 2>&1
if %errorlevel% equ 0 (
    echo [OK] Stress Test PASSED
) else (
    echo [FAIL] Stress Test FAILED
)

php test_stok_edge_cases.php > nul 2>&1
if %errorlevel% equ 0 (
    echo [OK] Edge Cases Test PASSED
) else (
    echo [FAIL] Edge Cases Test FAILED
)

echo.
echo ========================================
echo ALL TESTS COMPLETED
echo ========================================
