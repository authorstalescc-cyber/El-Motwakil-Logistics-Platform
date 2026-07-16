#!/bin/bash
# تشغيل نظام المتوكل + فتحه على النت برابط عام (يفتح من أي مكان)
# انقر نقراً مزدوجاً على هذا الملف
cd "$(dirname "$0")" || exit 1

BREW=/opt/homebrew/bin
CF=$BREW/cloudflared
PORT=8000

echo "بدء تشغيل نظام المتوكل…"

# تأكد أن MySQL يعمل
if ! $BREW/mysql -u root -e "SELECT 1" >/dev/null 2>&1; then
    echo "تشغيل خادم MySQL…"
    brew services start mysql >/dev/null 2>&1
    sleep 4
fi

# تأكد أن قاعدة البيانات موجودة، وإلا ثبّت
if ! $BREW/mysql -u root -e "USE almotawakel" >/dev/null 2>&1; then
    echo "أول تشغيل — تثبيت قاعدة البيانات ونقل البيانات…"
    [ -f database/old_data.json ] || python3 database/export_old_data.py
    php bin/install.php
fi

# لو النظام شغّال من نافذة قديمة، البورت هيبقى مشغول والخادم الجديد هيفشل بصمت
# (وتفضل شغّال على النسخة القديمة). نقفل القديم الأول عشان نبدأ نضيف.
if lsof -ti tcp:$PORT >/dev/null 2>&1; then
    echo "فيه نسخة قديمة شغّالة — بقفلها الأول…"
    lsof -ti tcp:$PORT | xargs kill 2>/dev/null
    sleep 2
fi

# شغّل خادم PHP في الخلفية (بعدة عُمّال حتى لا يُجمّد طلب بطيء بقية الصفحات)
PHP_CLI_SERVER_WORKERS=6 php -S 0.0.0.0:$PORT -t public public/router.php >/tmp/almotawakel-php.log 2>&1 &
PHP_PID=$!
sleep 2

# اتأكد إن الخادم قام فعلاً قبل ما نفتح النفق عليه
if ! curl -s -o /dev/null --max-time 5 http://localhost:$PORT/login; then
    echo "❌ الخادم مقامش — راجع /tmp/almotawakel-php.log"
    tail -3 /tmp/almotawakel-php.log
    echo "اضغط Enter للخروج…"; read -r _; exit 1
fi

# افتح النفق (الرابط العام) في الخلفية.
# --protocol http2: النفق افتراضياً بيستخدم QUIC على UDP، وده متحجب على شبكات
# الموبايل/الهوت سبوت فالنفق ياخد اسم وما يوصلش (خطأ 1033/530 عند السائق).
# http2 بيمشي على TCP 443 زي أي موقع — أبطأ شوية بس بيشتغل في كل مكان.
rm -f /tmp/almotawakel-tunnel.log
$CF tunnel --protocol http2 --url http://localhost:$PORT >/tmp/almotawakel-tunnel.log 2>&1 &
CF_PID=$!

# استنى ظهور الرابط العام
echo "جاري تجهيز الرابط العام… (لحظات)"
URL=""
for i in $(seq 1 30); do
    URL=$(grep -oE "https://[a-z0-9-]+\.trycloudflare\.com" /tmp/almotawakel-tunnel.log | head -1)
    [ -n "$URL" ] && break
    sleep 1
done

# ⚠️ مهم: cloudflared بيطبع الرابط قبل ما النفق يوصل فعلاً بحوالي 30 ثانية.
# لو اتنسخ الرابط واتبعت في الفترة دي السائق ياخد خطأ 1033. فمنستنى التأكيد.
#
# التأكيد بياخد إشارتين، والأولى هي الأساس:
#  1) سطر "Registered tunnel connection" في سجل النفق = النفق اتوصل فعلاً.
#     دي إشارة محلية موثوقة ومالهاش دعوة بالـ DNS.
#  2) طلب HTTP حقيقي — بس **بتخطّي DNS الجهاز** عبر --resolve، لأن ماك بيكاش
#     نتيجة سلبية (NXDOMAIN) لو حد سأل عن الاسم قبل ما النفق يتسجّل، فيفضل
#     يقول "مش لاقيه" على الجهاز ده بس بينما الرابط شغّال عند كل الناس.
READY=""
if [ -n "$URL" ]; then
    echo "جاري التأكد إن الرابط بيفتح فعلاً… (ممكن ياخد نص دقيقة)"
    for i in $(seq 1 40); do
        if grep -q "Registered tunnel connection" /tmp/almotawakel-tunnel.log 2>/dev/null; then
            READY="yes"; break
        fi
        sleep 2
    done
fi

# تأكيد نهائي end-to-end بتخطّي كاش الجهاز (فشله ما يمنعش — السجل هو الحكم)
if [ -n "$READY" ]; then
    HOST=$(echo "$URL" | sed 's|https://||')
    IP=$(dig +short +time=4 "$HOST" 2>/dev/null | grep -E '^[0-9.]+$' | head -1)
    if [ -n "$IP" ]; then
        CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 20 \
               --resolve "$HOST:443:$IP" "$URL/login" 2>/dev/null)
        [ "$CODE" = "200" ] || [ "$CODE" = "302" ] || echo "  (تنبيه: النفق متوصّل بس الاختبار رجّع $CODE — جرّب الرابط بنفسك)"
    fi
fi

# سجّل الرابط العام في النظام — عشان روابط تتبع الشحنات اللي بتتبعت للسائقين
# تتبني عليه بدل localhost. من غير الخطوة دي الروابط مش هتفتح عند السائق.
if [ -n "$URL" ] && [ -n "$READY" ]; then
    php bin/set_public_url.php "$URL" >/dev/null 2>&1
fi

# $'…' (ANSI-C quoting) عشان bash يفسّر \033 فعلاً. بعلامات التنصيص العادية
# كان بيطبعها حروف خام (\033[32m) على الشاشة لأن الشيبانج bash مش zsh.
g=$'\033[32m'; c=$'\033[36m'; y=$'\033[33m'; b=$'\033[1m'; r=$'\033[0m'
echo ""
echo "${c}═══════════════════════════════════════════════${r}"
echo "${b}  نظام المتوكل — شغّال على النت${r}"
echo "${c}═══════════════════════════════════════════════${r}"
echo "  ${b}على هذا الجهاز:${r} ${g}http://localhost:${PORT}${r}"
if [ -n "$URL" ] && [ -n "$READY" ]; then
    echo "  ${b}الرابط العام:${r}   ${g}${URL}${r}"
    echo "  ${y}(اتأكدنا إنه بيفتح فعلاً — ابعته لأي حد)${r}"
    echo "  ${y}روابط تتبع الشحنات دلوقتي شغّالة على الرابط ده.${r}"
    echo ""
    echo "  ${y}⚠️ الرابط ده بيموت لما تقفل النافذة دي، والمرة الجاية هيبقى رابط${r}"
    echo "  ${y}   مختلف. فأي رابط تتبع بعتّه لسائق قبل كده مش هيفتح — ادخل على${r}"
    echo "  ${y}   «تتبع الشحنات» واضغط «جدّد الرابط» وابعته تاني.${r}"
elif [ -n "$URL" ]; then
    echo "  ${y}الرابط العام اتعمل بس لسه مابيردّش:${r} ${URL}"
    echo "  ${y}استنى دقيقة وجرّبه. لو فضل مش شغّال، غيّر شبكة النت${r}"
    echo "  ${y}(الهوت سبوت أحياناً بيحجب النفق) — أو راجع /tmp/almotawakel-tunnel.log${r}"
    echo "  ${y}ما سجّلناش الرابط في النظام عشان ما تبعتش رابط مكسور للسائقين.${r}"
else
    echo "  ${y}تعذّر تجهيز الرابط العام — راجع /tmp/almotawakel-tunnel.log${r}"
    echo "  ${y}تتبع الشحنات مش هيشتغل من غير الرابط العام.${r}"
fi
echo ""
echo "  ${y}الدخول:${r} باسم المستخدم وكلمة السر المعتادين"
echo "  ${y}للإيقاف:${r} اقفل النافذة أو اضغط Control + C"
echo "${c}═══════════════════════════════════════════════${r}"
echo ""

# عند الإغلاق أوقف الخادم والنفق معاً
trap "kill $PHP_PID $CF_PID 2>/dev/null" EXIT
wait $PHP_PID
