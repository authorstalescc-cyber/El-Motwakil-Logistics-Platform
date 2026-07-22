#!/usr/bin/env bash
# ============================================================================
# رفع النظام على سيرفر الإنتاج (Hostinger — portal.almutawakkil.com)
#
# قرار المالك (2026-07-21): أي تغيير جاهز يتاخد له **نسخة احتياطية ثم يترفع
# على طول بلا تأكيد**. السكربت ده هو الطريقة الرسمية للرفع — بيعمل الترتيب
# الصح كل مرة فمفيش خطوة تتنسي.
#
# الخطوات بالترتيب:
#   1) نسخة احتياطية من قاعدة **الإنتاج** (على السيرفر + نسخة تتنزّل محليًا).
#   2) رفع الكود بـrsync --checksum وبلا --delete.
#   3) تظبيط الصلاحيات (755/644 + config 600).
#   4) تشغيل الهجرات المطلوبة (--migrate) والفحوصات (--verify) على السيرفر.
#   5) التأكد إن الموقع بيرد 200، وطباعة ملخّص.
#
# ⚠️ مصائد متحقَّق منها فعليًا ومعالَجة هنا (متشيلهاش):
#   • **بلا --delete أبدًا** — السيرفر فيه ملفات مش في المستودع (.htaccess،
#     config/config.php، المرفقات) وأي --delete بيمسحها.
#   • **--checksum إلزامي** — rsync الافتراضي بيقارن بالحجم+الوقت وبيتخطّى
#     ملفًا اتعدّل بعد بدء النقل بصمت.
#   • **المرفقات مستثناة** (storage/private_uploads + public/uploads) عشان
#     صور إيصالات المناديب على الإنتاج ما تتكتبش فوقها بنسخة محلية.
#   • **config/config.php مستثنى** — بيانات السيرفر مختلفة وchmod 600.
#
# التشغيل:
#   bash bin/deploy.sh
#   bash bin/deploy.sh --migrate bin/migrate_v47.php --verify bin/verify_v47.php
#   bash bin/deploy.sh --dry-run          # يوري اللي هيترفع بلا ما يرفع
# ============================================================================
set -euo pipefail

HOST="almutawakkil"
REMOTE="/home/u348648638/domains/almutawakkil.com/public_html/al-mutawakkil-system"
LOCAL="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STAMP="$(date +%Y%m%d-%H%M%S)"
DRY=0
MIGRATE=()
VERIFY=()

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run) DRY=1; shift ;;
    --migrate) MIGRATE+=("$2"); shift 2 ;;
    --verify)  VERIFY+=("$2");  shift 2 ;;
    *) echo "خيار مش معروف: $1" >&2; exit 2 ;;
  esac
done

say() { printf '\n\033[1;36m%s\033[0m\n%s\n' "$1" "────────────────────────────────────────────────────────────"; }
ok()  { printf '  \033[32m✓\033[0m %s\n' "$1"; }
die() { printf '  \033[31m✗ %s\033[0m\n' "$1" >&2; exit 1; }

EXCLUDES=(
  --exclude '.git' --exclude '.gitignore' --exclude '.DS_Store'
  --exclude 'config/config.php'
  --exclude 'storage/backups' --exclude 'database/backups'
  --exclude 'storage/private_uploads' --exclude 'public/uploads'
  --exclude 'storage/*.log' --exclude '*.log' --exclude '*.command'
  --exclude '__pycache__' --exclude '.venv' --exclude 'node_modules'
)
# ⚠️ **`.htaccess` بقى مرفوعًا من المستودع (مش مستثنى زي الأول).**
#    هو الحاجز الوحيد اللي بيمنع الإنترنت من config/config.php وbin/*.php،
#    وكونه كان على السيرفر بس معناه إنه لو اتمسح محدش هيلاحظ. دلوقتي هو
#    متحفوظ ومتراجَع بعد كل رفع في خطوة 7.
#    وعشان أي غلطة في قاعدة Apache بتوقّع الموقع كله بـ500، بناخد منه نسخة
#    على السيرفر قبل الرفع وبنرجّعها تلقائيًّا لو الموقع وقع (خطوة 6).

# ── 0) فحص الأمان المحلي ──────────────────────────────────────────────────
# ⚠️ بوابة قبل أي حاجة تانية. كود فشل فحصه الأمني ما يترفعش — الفحص ده بيمنع
#    رجوع ثغرات اتصلّحت فعلاً (XSS في المخرجات، سكربتات bin مفتوحة على الويب،
#    .htaccess ناقص أو فيه قاعدة بتوقّع السيرفر).
say "0) فحص الأمان المحلي قبل الرفع"
if php "$LOCAL/bin/verify_security.php" > /tmp/alm_sec_local 2>&1; then
  ok "$(grep -o 'نجح: [0-9]*' /tmp/alm_sec_local | head -1) — كل الفحوصات الأمنية عدّت"
else
  sed 's/^/  /' /tmp/alm_sec_local >&2
  die "فشل الفحص الأمني المحلي — الرفع اتوقف. صلّح اللي فوق الأول."
fi

# ── 1) نسخة احتياطية من قاعدة الإنتاج ─────────────────────────────────────
# ⚠️ **بتتاخد عبر SSH مش من داخل النظام.** استضافة الإنتاج بتقفل كل دوال تشغيل
#    العمليات (exec/shell_exec/proc_open/popen/passthru)، فـ`DatabaseBackup`
#    (وزرار النسخ الاحتياطي في الواجهة) **مستحيل يشتغل هناك**. بنقرا بيانات
#    الاتصال من config بتاع السيرفر ونشغّل mysqldump من الشِل.
# ⚠️ كلمة السر بتتحطّ في MYSQL_PWD مش في سطر الأوامر، فما بتظهرش في ps.
say "1) نسخة احتياطية من قاعدة الإنتاج"
REMOTE_BACKUP="database/backups/pre-deploy-$STAMP.sql.gz"
if [[ $DRY -eq 1 ]]; then
  ok "(تجربة) اتخطّت النسخة الاحتياطية"
else
  ssh "$HOST" "set -euo pipefail
    cd '$REMOTE'
    eval \"\$(php -r '\$c = require \"config/config.php\"; \$d = \$c[\"db\"];
      printf(\"DBH=%s DBP=%s DBN=%s DBU=%s; export MYSQL_PWD=%s\",
        escapeshellarg(\$d[\"host\"]), escapeshellarg((string)\$d[\"port\"]),
        escapeshellarg(\$d[\"name\"]), escapeshellarg(\$d[\"user\"]),
        escapeshellarg((string)(\$d[\"pass\"] ?? \"\")));')\"
    mkdir -p database/backups && chmod 700 database/backups
    # ⚠️ صلاحية المستخدم على الاستضافة مربوطة بـuser@localhost (سوكيت). لو
    #    وصّلنا TCP بيتحوّل لـ::1 ويترفض بـ«Access denied» — فالسوكيت الأول.
    SOCK=/var/lib/mysql/mysql.sock
    if [ -S \"\$SOCK\" ]; then CONN=\"--protocol=socket --socket=\$SOCK\"
    else CONN=\"-h\$DBH -P\$DBP\"; fi
    trap \"rm -f '$REMOTE_BACKUP'\" EXIT   # فشل النسخة ما يسيبش ملف نص-نص يتلبس نسخة سليمة
    mysqldump \$CONN --single-transaction --no-tablespaces --routines --triggers \
              --default-character-set=utf8mb4 \
              -u\"\$DBU\" \"\$DBN\" | gzip -c > '$REMOTE_BACKUP'
    chmod 600 '$REMOTE_BACKUP'
    # ⚠️ \`gzip -t\` لوحده مش كفاية: ملف مضغوط فاضي (20 بايت) بيعدّي منه. الدليل
    #    القاطع إن الـdump كمل هو سطر النهاية اللي mysqldump بيكتبه في الآخر.
    gzip -dc '$REMOTE_BACKUP' | tail -3 | grep -q 'Dump completed' \
      || { echo 'الـdump ما كملش — مفيش سطر Dump completed' >&2; exit 1; }
    trap - EXIT
    ls -lh '$REMOTE_BACKUP' | awk '{print \$5}'" > /tmp/alm_backup_size 2>/tmp/alm_backup_err \
    || { sed 's/^/  /' /tmp/alm_backup_err >&2; die "فشلت النسخة الاحتياطية — الرفع اتوقف قبل ما يبدأ."; }
  ok "على السيرفر: $REMOTE_BACKUP ($(tr -d '\n' < /tmp/alm_backup_size))"

  mkdir -p "$LOCAL/database/backups/prod"
  scp -q "$HOST:$REMOTE/$REMOTE_BACKUP" "$LOCAL/database/backups/prod/" \
    || die "النسخة اتعملت على السيرفر بس ما اتنزلتش محليًا."
  # نتأكد إن الملف اللي نزل سليم فعلاً — نسخة تالفة أسوأ من نسخة مش موجودة
  LOCAL_COPY="$LOCAL/database/backups/prod/$(basename "$REMOTE_BACKUP")"
  gzip -t "$LOCAL_COPY" || die "النسخة اللي نزلت تالفة."
  gzip -dc "$LOCAL_COPY" | tail -3 | grep -q 'Dump completed' \
    || die "النسخة اللي نزلت ناقصة (مفيش سطر Dump completed)."
  ok "نسخة محلية سليمة: database/backups/prod/$(basename "$REMOTE_BACKUP")"

  # ── تنظيف: نسيب آخر KEEP نسخة بس ────────────────────────────────────────
  # كل رفعة بتعمل نسخة، فمن غير تنظيف بتتراكم للأبد على السيرفر وعلى الجهاز.
  # ⚠️ الترتيب بالاسم مش بالتاريخ عن قصد — الاسم فيه STAMP بصيغة
  #    YYYYmmdd-HHMMSS فترتيبه الأبجدي = ترتيبه الزمني، ومش بيتأثر لو اتغيّر
  #    وقت تعديل الملف لأي سبب.
  KEEP=20
  PRUNED=$(ssh "$HOST" "cd '$REMOTE/database/backups' 2>/dev/null && \
    ls -1 pre-deploy-*.sql.gz 2>/dev/null | sort | head -n -$KEEP | tee /dev/stderr | xargs -r rm -f" 2>&1 >/dev/null | wc -l | tr -d ' ')
  [[ "$PRUNED" -gt 0 ]] && ok "اتمسح $PRUNED نسخة قديمة من السيرفر (بنسيب آخر $KEEP)"

  LOCAL_OLD=$(ls -1 "$LOCAL/database/backups/prod/"pre-deploy-*.sql.gz 2>/dev/null | sort | head -n -$KEEP || true)
  if [[ -n "$LOCAL_OLD" ]]; then
    echo "$LOCAL_OLD" | xargs rm -f
    ok "اتمسح $(echo "$LOCAL_OLD" | wc -l | tr -d ' ') نسخة قديمة محليًّا (بنسيب آخر $KEEP)"
  fi
fi

# ── 1ب) نسخة أمان من .htaccess على السيرفر ────────────────────────────────
# غلطة واحدة في قاعدة Apache = الموقع كله 500. بناخد نسخة قبل ما نكتب فوقها
# عشان خطوة 6 تقدر ترجّعها فورًا وتلقائيًّا.
if [[ $DRY -eq 0 ]]; then
  ssh "$HOST" "cd '$REMOTE' && \
    { [ -f .htaccess ] && cp -p .htaccess '.htaccess.pre-$STAMP'; } ; \
    { [ -f public/.htaccess ] && cp -p public/.htaccess 'public/.htaccess.pre-$STAMP'; } ; true" \
    && ok "نسخة أمان من .htaccess (.htaccess.pre-$STAMP)"
fi

# ── 2) رفع الكود ──────────────────────────────────────────────────────────
say "2) رفع الكود (rsync --checksum بلا --delete)"
RSYNC_FLAGS=(-az --checksum --itemize-changes --human-readable)
[[ $DRY -eq 1 ]] && RSYNC_FLAGS+=(--dry-run)
CHANGED="$(rsync "${RSYNC_FLAGS[@]}" "${EXCLUDES[@]}" \
  -e "ssh" "$LOCAL/" "$HOST:$REMOTE/" | grep -E '^[<>ch]' || true)"
if [[ -z "$CHANGED" ]]; then
  ok "مفيش ملفات اتغيّرت — السيرفر متطابق أصلاً"
else
  echo "$CHANGED" | sed 's/^/  /'
  ok "$(echo "$CHANGED" | wc -l | tr -d ' ') ملف اتنقل"
fi

if [[ $DRY -eq 1 ]]; then
  say "تجربة بس — مفيش حاجة اتغيّرت على السيرفر."
  exit 0
fi

# ── 2ب) مطابقة البصمات ────────────────────────────────────────────────────
# ⚠️ **إلزامية.** rsync ممكن يقول «transferred» والملف على السيرفر يفضل قديم
#    (اتصادف فعليًا). وكمان rsync بتاع ماك (openrsync) بيختلف مع rsync 3.x
#    بتاع السيرفر فبيعلّم ملفات مالهاش أي تغيير — يعني قايمة فوق مش دليل.
#    الدليل الوحيد هو المقارنة دي.
if [[ -n "$CHANGED" ]]; then
  say "2ب) مطابقة بصمات الملفات اللي اتنقلت"
  PATHS=$(echo "$CHANGED" | sed 's/^[^ ]* //' | grep -v '/$' || true)
  if [[ -n "$PATHS" ]]; then
    REMOTE_SUMS=$(ssh "$HOST" "cd '$REMOTE' && md5sum $(echo "$PATHS" | tr '\n' ' ') 2>/dev/null")
    BAD=0
    while IFS= read -r f; do
      [[ -f "$LOCAL/$f" ]] || continue
      lsum=$(md5 -q "$LOCAL/$f")
      rsum=$(echo "$REMOTE_SUMS" | awk -v p="$f" '$2==p{print $1}')
      if [[ "$lsum" != "$rsum" ]]; then printf '  \033[31m✗ %s\033[0m\n' "$f"; BAD=1; fi
    done <<< "$PATHS"
    [[ $BAD -eq 0 ]] || die "فيه ملفات على السيرفر مش مطابقة للمحلي — الرفع فشل فعليًا."
    ok "كل الملفات على السيرفر مطابقة للمحلي بالبصمة"
  fi
fi

# ── 3) الصلاحيات ──────────────────────────────────────────────────────────
say "3) تظبيط الصلاحيات"
# ⚠️ النسخ الاحتياطية والمرفقات مستثناة من التوسيع: ملفاتها فيها بيانات وبتتخزّن
#    بـ600/700 عن قصد، وتحويلها 644 بيخلّيها مقروءة لأي مستخدم على السيرفر.
SKIP="! -path './.git/*' ! -path './storage/backups/*' ! -path './database/backups/*' ! -path './storage/private_uploads/*'"
ssh "$HOST" "cd '$REMOTE' && \
  find . -type d $SKIP -exec chmod 755 {} + && \
  find . -type f $SKIP -exec chmod 644 {} + && \
  chmod 700 storage/backups database/backups 2>/dev/null; \
  find storage/backups database/backups -type f -exec chmod 600 {} + 2>/dev/null; \
  chmod 600 config/config.php" || die "فشل تظبيط الصلاحيات."
ok "755 للمجلدات · 644 للملفات · 600 لـconfig والنسخ الاحتياطية (منع 403)"

# ── 4) الهجرات والفحوصات ──────────────────────────────────────────────────
if [[ ${#MIGRATE[@]} -gt 0 ]]; then
  say "4) الهجرات على قاعدة الإنتاج"
  for m in "${MIGRATE[@]}"; do
    echo "  ← $m"
    ssh "$HOST" "cd '$REMOTE' && php $m" 2>&1 | sed 's/^/    /' || die "فشلت الهجرة $m"
  done
fi

if [[ ${#VERIFY[@]} -gt 0 ]]; then
  say "5) الفحوصات على السيرفر"
  for v in "${VERIFY[@]}"; do
    echo "  ← $v"
    ssh "$HOST" "cd '$REMOTE' && php $v" 2>&1 | tail -20 | sed 's/^/    /' || die "فشل الفحص $v"
  done
fi

# ── 6) الموقع شغّال؟ (مع رجوع تلقائي لو .htaccess وقّعه) ──────────────────
say "6) التأكد إن الموقع شغّال"
CODE="$(curl -s -o /dev/null -w '%{http_code}' https://portal.almutawakkil.com/login)"
if [[ "$CODE" != "200" ]]; then
  printf '  \033[31m✗ الموقع رد بـ%s — بنرجّع .htaccess القديم فورًا\033[0m\n' "$CODE"
  ssh "$HOST" "cd '$REMOTE' && \
    { [ -f '.htaccess.pre-$STAMP' ] && mv '.htaccess.pre-$STAMP' .htaccess; } ; \
    { [ -f 'public/.htaccess.pre-$STAMP' ] && mv 'public/.htaccess.pre-$STAMP' public/.htaccess; } ; true"
  CODE2="$(curl -s -o /dev/null -w '%{http_code}' https://portal.almutawakkil.com/login)"
  [[ "$CODE2" == "200" ]] \
    && die "رجّعنا .htaccess القديم والموقع اشتغل تاني. السبب في قواعد .htaccess الجديدة — صلّحها وأعد الرفع." \
    || die "الموقع لسه واقع بـ$CODE2 حتى بعد رجوع .htaccess — السبب مش في .htaccess. راجع فورًا."
fi
ok "صفحة الدخول بترد 200"
CODE_M="$(curl -s -o /dev/null -w '%{http_code}' https://portal.almutawakkil.com/m/login)"
[[ "$CODE_M" == "200" ]] && ok "بوابة المندوب /m بترد 200" || die "بوابة المندوب ردّت بـ$CODE_M."

# ── 7) فحص الأمان على الموقع الشغّال ──────────────────────────────────────
# ⚠️ الخطوة دي بتقيس الواقع مش النية: بتجرّب فعليًّا تفتح config/config.php
#    وbin/install.php من برّه، وبتتأكد إن رؤوس الحماية وصلت للمتصفح فعلاً
#    (الاستضافة بتكتب CSP خاصة بيها فوق بتاعتنا لو مش مضبوطة صح).
say "7) فحص الأمان على الموقع الشغّال"
if php "$LOCAL/bin/verify_security.php" --remote https://portal.almutawakkil.com > /tmp/alm_sec_remote 2>&1; then
  sed 's/^/  /' /tmp/alm_sec_remote
  ok "كل الفحوصات الأمنية على السيرفر عدّت"
else
  sed 's/^/  /' /tmp/alm_sec_remote >&2
  printf '\n  \033[31m✗ فيه حماية على السيرفر مش شغّالة — الموقع شغّال بس مكشوف. صلّح فورًا.\033[0m\n' >&2
  exit 1
fi

# ── 8) صلاحيات مستخدم قاعدة الإنتاج ───────────────────────────────────────
# ⚠️ بتتفحص **على السيرفر** مش محليًّا: قاعدة التطوير صلاحياتها واسعة بطبيعتها،
#    واللي يهمّنا هو مستخدم الإنتاج. أخطر صلاحية هي FILE — بتحوّل أي ثغرة SQL
#    لاختراق كامل (قراءة config.php أو كتابة webshell).
say "8) صلاحيات مستخدم قاعدة الإنتاج"
if ssh "$HOST" "cd '$REMOTE' && php bin/verify_security.php --db-privs" > /tmp/alm_privs 2>&1; then
  grep -aE '✓|!' /tmp/alm_privs | sed 's/^/  /' | tail -8
  ok "صلاحيات قاعدة الإنتاج مضبوطة"
else
  sed 's/^/  /' /tmp/alm_privs >&2
  die "صلاحيات مستخدم قاعدة الإنتاج فيها مشكلة — راجع فورًا."
fi

# نضّف نسخ .htaccess الاحتياطية بعد ما اتأكدنا إن كل حاجة تمام
ssh "$HOST" "cd '$REMOTE' && rm -f '.htaccess.pre-$STAMP' 'public/.htaccess.pre-$STAMP'; true"

say "تم الرفع بنجاح — $STAMP"
