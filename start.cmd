@echo off
setlocal EnableExtensions DisableDelayedExpansion

pushd "%~dp0"
if errorlevel 1 exit /b 1

set "RASPBERRY_PHP_DIR=%~dp0bin\php"
set "OPENSSL_CONF=%RASPBERRY_PHP_DIR%\extras\ssl\openssl.cnf"
set "SSL_CERT_FILE=%RASPBERRY_PHP_DIR%\extras\ssl\cacert.pem"
set "PATH=%RASPBERRY_PHP_DIR%;%PATH%"

for %%F in (
    "%RASPBERRY_PHP_DIR%\php.exe"
    "%RASPBERRY_PHP_DIR%\php.ini"
    "%OPENSSL_CONF%"
    "%RASPBERRY_PHP_DIR%\extras\ssl\cacert.pem"
) do (
    if not exist "%%~F" (
        echo [ERROR] Missing file: "%%~F"
        popd
        pause
        exit /b 1
    )
)

if exist "RaspberryX.phar" (
    "%RASPBERRY_PHP_DIR%\php.exe" -c "%RASPBERRY_PHP_DIR%\php.ini" -r "require 'phar://RaspberryX.phar/src/raspberry/RaspberryX.php';"
) else (
    if not exist "src\raspberry\RaspberryX.php" (
        echo [ERROR] RaspberryX.phar and the source entry point are missing.
        popd
        pause
        exit /b 1
    )

    "%RASPBERRY_PHP_DIR%\php.exe" -c "%RASPBERRY_PHP_DIR%\php.ini" "src\raspberry\RaspberryX.php"
)

set "RASPBERRY_EXIT=%ERRORLEVEL%"
popd

if not "%RASPBERRY_EXIT%"=="0" pause

exit /b %RASPBERRY_EXIT%