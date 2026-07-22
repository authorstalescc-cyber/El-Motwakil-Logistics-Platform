#!/usr/bin/env bash
# رفع موقع المتوكل على استضافة هوستنجر عبر SSH/rsync
#
# الاستخدام:
#   ./deploy.sh <ssh-host> [<المسار على السيرفر>]
#
# مثال (بعد إضافة المضيف في ~/.ssh/config):
#   ./deploy.sh almotawakel
#   ./deploy.sh almotawakel /home/uXXXXXX/domains/example.com/public_html
#
# ملاحظة: السكربت لا يحذف أي ملف على السيرفر إلا لو مرّرت --delete صراحةً.

set -euo pipefail

HOST="${1:-}"
REMOTE_PATH="${2:-public_html}"
SRC="$(cd "$(dirname "$0")" && pwd)"

if [[ -z "$HOST" ]]; then
  echo "خطأ: لم تحدّد المضيف."
  echo "الاستخدام: ./deploy.sh <ssh-host> [<المسار على السيرفر>]"
  exit 1
fi

echo "المصدر : $SRC"
echo "الوجهة : $HOST:$REMOTE_PATH"
echo

# التحقق من الاتصال قبل أي نقل
echo "» اختبار الاتصال…"
ssh -o ConnectTimeout=15 -o BatchMode=yes "$HOST" "mkdir -p '$REMOTE_PATH' && echo 'الاتصال سليم'"

# معاينة أولاً — لا يُنقل شيء
echo
echo "» معاينة الملفات التي ستُرفع (تشغيل تجريبي):"
rsync -avz --dry-run \
  --exclude '.DS_Store' \
  --exclude 'deploy.sh' \
  --exclude '.git*' \
  "$SRC"/ "$HOST:$REMOTE_PATH"/

echo
read -r -p "هل تريد المتابعة والرفع فعليًا؟ [y/N] " ok
[[ "$ok" == "y" || "$ok" == "Y" ]] || { echo "أُلغيت العملية."; exit 0; }

rsync -avz \
  --exclude '.DS_Store' \
  --exclude 'deploy.sh' \
  --exclude '.git*' \
  "$SRC"/ "$HOST:$REMOTE_PATH"/

echo
echo "✓ تم الرفع بنجاح."
echo "  تذكير: فعّل شهادة SSL من لوحة تحكم هوستنجر إن لم تكن مفعّلة."
