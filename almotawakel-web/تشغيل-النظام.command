#!/bin/bash
# تشغيل نظام المتوكل بنقرة واحدة — انقر نقراً مزدوجاً على هذا الملف
cd "$(dirname "$0")" || exit 1

echo "بدء تشغيل نظام المتوكل…"

# تأكد أن MySQL يعمل
if ! /opt/homebrew/bin/mysql -u root -e "SELECT 1" >/dev/null 2>&1; then
    echo "تشغيل خادم MySQL…"
    brew services start mysql >/dev/null 2>&1
    sleep 4
fi

# تأكد أن قاعدة البيانات موجودة، وإلا ثبّت
if ! /opt/homebrew/bin/mysql -u root -e "USE almotawakel" >/dev/null 2>&1; then
    echo "أول تشغيل — تثبيت قاعدة البيانات ونقل البيانات…"
    [ -f database/old_data.json ] || python3 database/export_old_data.py
    php bin/install.php
fi

# شغّل الخادم
php bin/serve.php
