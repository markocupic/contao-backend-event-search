:: Run easy-coding-standard (ecs) via this batch file inside your IDE e.g. PhpStorm (Windows only)
:: Install inside PhpStorm the  "Batch Script Support" plugin
:: Uses vendor/bin/ecs of this bundle if installed (composer install), otherwise the ecs binary of the Contao project
cd /d "%~dp0..\..\..\.."

if exist "vendor\bin\ecs" (
    php vendor\bin\ecs check --config tools/ecs/config/default.php
) else (
    php ..\..\..\vendor\bin\ecs check --config tools/ecs/config/default.php
)
