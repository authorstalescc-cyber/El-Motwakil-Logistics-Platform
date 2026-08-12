#!/bin/bash
# ══════════════════════════════════════════════════════════════════════════
# نسخة احتياطية يومية لقاعدة الإنتاج عبر `mysqldump` — **مسار احتياطي بس**.
#
#   0 3 * * *  /bin/bash /home/u348648638/domains/almutawakkil.com/public_html/al-mutawakkil-system/bin/backup_cron.sh
#
# ⚠️⚠️ **مش ده اللي بياخد النسخة اليومية دلوقتي.** السكربت ده كان سليمًا
#    ١٠٠٪ و**قعد ١٥ يوم ما اشتغلش ولا مرة** لأنه محتاج كرون شِل يتظبّط بالإيد
#    من لوحة هوستنجر — وما اتظبّطش (و`crontab` نفسها مش موجودة على الحساب ده،
#    فمكانش فيه طريقة أعمله من SSH). الدرس: **حل بيحتاج ضغطة من حد = حل مش
#    شغّال.** النسخة اليومية بقت في `App\Models\AutoBackup` وبتتاخد من نبضة
#    `bin/push_worker.php` الشغّالة أصلاً كل ٥ دقايق.
#
#    السكربت ده فاضل لأنه: (١) بيستخدم `mysqldump` الحقيقي فهو **مرجع** نقارن
#    بيه، (٢) شغّال من SSH لو احتجت نسخة فورية بلا PHP. بيكتب نفس اسم الملف
#    ونفس علامات `settings`، وفحص «مرة واحدة في اليوم» في `AutoBackup` بيمنع
#    ازدواج النسخ لو الاتنين اشتغلوا.
#
# ⚠️ **ليه سكربت شِل مش PHP:** استضافة الإنتاج بتقفل كل دوال تشغيل العمليات
#    (`exec`/`shell_exec`/`proc_open`/`popen`/`passthru`)، فـ`DatabaseBackup`
#    وزرار النسخ الاحتياطي في الواجهة **مستحيل يشتغلوا هناك**. الشِل نفسه
#    عنده `mysqldump` عادي، والكرون بيشغّل أوامر شِل — فده المسار الوحيد.
#
# ⚠️ **كلمة السر في `MYSQL_PWD` مش في سطر الأوامر** عشان ما تظهرش في `ps`.
#
# ⚠️ **`gzip -t` لوحده مش كفاية:** ملف مضغوط فاضي (٢٠ بايت) بيعدّي منه.
#    الدليل القاطع إن الـdump كمل هو سطر `Dump completed` اللي mysqldump
#    بيكتبه في الآخر. نسخة تالفة أسوأ من نسخة مش موجودة، لأنها بتدّي
#    طمأنينة كاذبة.
#
# ⚠️ **الفشل لازم يبان.** السكربت بيسجّل نتيجته في جدول `settings`
#    (`backup_last_ok` / `backup_last_msg`)، ولوحة المراقبة الأمنية بتعرضها —
#    فنسخة بطّلت تشتغل بتبان بدل ما تفضل ساكتة شهور.
# ══════════════════════════════════════════════════════════════════════════
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1

DIR="database/backups"
KEEP=30                                   # عدد النسخ اللي بتفضل على السيرفر
STAMP="$(date +%Y%m%d-%H%M%S)"
OUT="$DIR/daily-${STAMP}.sql.gz"

# بتسجّل النتيجة في settings عشان الواجهة تقدر تقول «آخر نسخة إمتى»
mark() {
    php -r '
        require "app/bootstrap.php";
        \App\Core\App::setSetting("backup_last_ok",  $argv[1]);
        \App\Core\App::setSetting("backup_last_msg", $argv[2]);
    ' "$1" "$2" >/dev/null 2>&1
}
fail() { echo "backup: $1" >&2; mark "" "$1"; exit 1; }

mkdir -p "$DIR" && chmod 700 "$DIR" || fail "مش قادر أعمل مجلد النسخ"

# بيانات الاتصال من config الإنتاج — php بيقرا الملف عادي (المقفول هو التشغيل بس)
CREDS="$(php -r '
    $c = require "config/config.php"; $d = $c["db"];
    printf("DBH=%s DBP=%s DBN=%s DBU=%s; export MYSQL_PWD=%s",
        escapeshellarg($d["host"]), escapeshellarg((string) $d["port"]),
        escapeshellarg($d["name"]), escapeshellarg($d["user"]),
        escapeshellarg((string) ($d["pass"] ?? "")));
')" || fail "مش قادر أقرا بيانات الاتصال"
eval "$CREDS"

# ⚠️ صلاحية المستخدم مربوطة بـuser@localhost (سوكيت). أي اتصال TCP بيتحوّل
#    لـ::1 ويترفض بـ«Access denied» — فالسوكيت الأول.
SOCK=/var/lib/mysql/mysql.sock
if [ -S "$SOCK" ]; then CONN="--protocol=socket --socket=$SOCK"; else CONN="-h$DBH -P$DBP"; fi

# ⚠️ الإنتاج MariaDB مش MySQL — `--set-gtid-purged` بترمي «unknown variable».
mysqldump $CONN --single-transaction --no-tablespaces --routines --triggers \
          --default-character-set=utf8mb4 -u"$DBU" "$DBN" 2>/dev/null | gzip -c > "$OUT"

# ⚠️ حالة الخروج من **mysqldump** نفسه لا من gzip (PIPESTATUS[0])
if [ "${PIPESTATUS[0]}" -ne 0 ]; then rm -f "$OUT"; fail "mysqldump فشل"; fi
chmod 600 "$OUT"

gzip -t "$OUT" 2>/dev/null           || { rm -f "$OUT"; fail "الملف المضغوط تالف"; }
gzip -dc "$OUT" | tail -3 | grep -q 'Dump completed' \
                                     || { rm -f "$OUT"; fail "الـdump ما كملش (مفيش Dump completed)"; }

SIZE="$(ls -lh "$OUT" | awk '{print $5}')"

# تنظيف: نسيب آخر $KEEP نسخة يومية بس. الترتيب بالاسم = الترتيب الزمني
# لأن الاسم فيه الختم — فمفيش اعتماد على تاريخ تعديل الملف.
ls -1 "$DIR"/daily-*.sql.gz 2>/dev/null | sort | head -n -"$KEEP" | while read -r old; do
    rm -f "$old"
done

mark "$(date '+%Y-%m-%d %H:%M:%S')" "تمام — ${SIZE}"
echo "backup: $OUT ($SIZE)"
