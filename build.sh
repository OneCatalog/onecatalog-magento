#!/usr/bin/env bash
# Собрать архив модуля Magento: onecatalog-magento-<ver>.zip (распаковать в app/code/).
set -euo pipefail
cd "$(dirname "$0")"
ver=$(grep -oE '"version" *: *"[0-9.]+"' app/code/OneCatalog/Import/composer.json | head -1 | grep -oE '[0-9.]+')
out="onecatalog-magento-${ver:-dev}.zip"
rm -f "$out"
( cd app/code && zip -rq "../../$out" OneCatalog -x '*.DS_Store' )
echo "✔ $out"
