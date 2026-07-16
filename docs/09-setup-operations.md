# 09 — التشغيل والصيانة

---

## 1. المتطلّبات

- **PHP 8+**، **MySQL 8/9** (Homebrew على Apple Silicon: `/opt/homebrew/bin`، root بلا كلمة سر).
- **Python 3 + openpyxl** — لنقل بيانات الإكسل مرة واحدة فقط، ولبناء ملفات الإكسل.

---

## 2. تشغيل نسخة الويب

### الأسهل (نقرة واحدة)
انقر نقرًا مزدوجًا على **`almotawakel-web/تشغيل-النظام.command`**.
السكربت: `cd` للمجلد → يتأكّد MySQL شغّال (`brew services start mysql`) → أول مرة يشغّل `export_old_data.py` (لو `old_data.json` مفقود) + `install.php` → يشغّل `serve.php`.

### اليدوي (Terminal)
```bash
cd "/Users/karimmokhtar/المتوكل/almotawakel-web"

# مرّة واحدة فقط:
brew services start mysql
python3 database/export_old_data.py     # يقرأ old-system-data.xlsx → old_data.json
php bin/install.php                      # ينشئ القاعدة + الـ schema + ينقل البيانات

# كل مرة:
php bin/serve.php                        # يشغّل الخادم
```
بعد التشغيل: افتح العنوان الظاهر (مثل `http://localhost:8000`). لأجهزة المكتب: العنوان `http://192.168.1.X:8000`.
`serve.php` يكتشف IPs الشبكة (`ipconfig getifaddr en0/en1`) ويشغّل `php -S 0.0.0.0:{port} -t public public/router.php` (المنفذ الافتراضي 8000).

### بيانات الدخول
```
admin / almotawakel
```
> تُنشأ عند التثبيت مشفّرة (`password_hash`, `PASSWORD_DEFAULT`). لتغييرها: عدّل `config/config.php` → `default_user`، ثم أعد `php bin/install.php`.

---

## 3. المثبِّت — `bin/install.php`

`php bin/install.php` (تثبيت/تحديث) أو `--fresh` (⚠️ حذف وإعادة إنشاء). الخطوات:
1. **إنشاء القاعدة** (`serverConn`، على `--fresh` يحذف أولًا) `CREATE DATABASE IF NOT EXISTS` بـ `utf8mb4_unicode_ci`.
2. **تحميل الـ schema** — يقرأ `database/schema.sql`، يشيل تعليقات `--`، يقسّم على `;`، ينفّذ كل جملة.
3. **بذر المستخدم الافتراضي** — `password_hash` لو غير موجود.
4. **نقل البيانات القديمة** — لو `old_data.json` موجود: يفرّغ bawalis/takhlis/generators/balances، يستورد lookups/balances/الكيانات (كل صف عبر `Calc::apply()`)، في transactions.
5. **بذر دليل الوكلاء** — لو `directory_seed.json` موجود: يفرّغ agent_company/agents/companies، يدرج ويربط، يطبّق renames في balances، يعيد بناء قوائم client/company.

> **⚠️ `install.php` ينفّذ `schema.sql` فقط — لا يشغّل الهجرات.** بعد تثبيت جديد شغّل كل الهجرات بالترتيب، لأن بعضها ينقل/يصحح بيانات حتى لو كانت الأعمدة موجودة في `schema.sql`:
> ```bash
> for v in {2..29}; do php "bin/migrate_v${v}.php" || exit 1; done
> ```
> كلها idempotent (آمنة لإعادة التشغيل). ترحيل البوالص القديمة نفسه خطوة مستقلة بعد v26: `php bin/import_legacy_to_live.php --dry-run` ثم `php bin/import_legacy_to_live.php`. راجع [05-database.md](05-database.md) §3.

---

## 4. النسخ الاحتياطي والاستعادة

- **نسخ:** من **الإعدادات ← تنزيل نسخة احتياطية** → ملف `.sql` كامل (عبر `mysqldump`، `BackupController`). النسخ محفوظة أيضًا في `database/backups/`.
- **استعادة:**
  ```bash
  mysql -u root almotawakel < ملف-النسخة.sql
  ```

---

## 5. إعادة النقل من الصفر

```bash
python3 database/export_old_data.py
php bin/install.php --fresh      # ⚠️ يحذف كل البيانات ويعيد النقل
# ثم شغّل الهجرات (راجع §3)
```

---

## 6. صيانة البيانات

### تطبيع الأسماء (دمج التهجئات المكرّرة)
```bash
php bin/normalize_names.php --dry     # معاينة بلا كتابة
php bin/normalize_names.php           # تطبيق فعلي
```
أو من الواجهة: **الإعدادات ← الصيانة ← تطبيع الأسماء**. (سبب وجودها: الجداول مربوطة بأسماء نصية بدون FK — راجع [05](05-database.md) و[10](10-conventions-gotchas.md).)

---

## 7. بناء/إعادة بناء ملفات الإكسل

```bash
cd "/Users/karimmokhtar/المتوكل"
python3 build_system.py          # النظام الأساسي (مع عيّنة)
python3 migrate.py               # ينقل البيانات الحقيقية للأساسي
python3 build_daily_closing.py   # التقفيل اليومي (يضمّن snapshot من قوائم الأساسي)
python3 sync_lists.py            # عند تغيّر القوائم (ملف التقفيل لازم مُغلق في Excel)
```
> المسارات hard-coded تحت `/Users/karimmokhtar/المتوكل/`. الملفان لازم في **نفس المجلد**. راجع [03-excel-system.md](03-excel-system.md).

---

## 8. ملخّص أوامر سريع

| المهمّة | الأمر |
|---------|-------|
| تشغيل الويب | `php bin/serve.php` (في `almotawakel-web/`) |
| تثبيت أول مرة | `php bin/install.php` |
| إعادة تثبيت كاملة | `php bin/install.php --fresh` |
| الهجرات | `php bin/migrate_v2.php` … `migrate_v29.php` |
| اختبار سجل الإدخالات/تاريخ البوليصة | `php bin/verify_v29.php` (داخل transaction ويعمل rollback) |
| اختبار الإدخال السريع الجماعي | `php bin/verify_quick_entries.php` (يحفظ/يرفض/يرندر ثم يعمل rollback) |
| فحص تصميم صفحة التقفيل | `php bin/verify_closing_redesign.php` (رندر قراءة فقط + الخطوات/الأقسام + تكرار IDs) |
| تطبيع الأسماء | `php bin/normalize_names.php [--dry]` |
| نقل بيانات الإكسل | `python3 database/export_old_data.py` |
| استعادة نسخة | `mysql -u root almotawakel < file.sql` |
| بناء إكسل | `python3 build_system.py` |
