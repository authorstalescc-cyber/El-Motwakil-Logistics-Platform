#!/usr/bin/env python3
"""مشغّل موحّد لاختبارات Playwright ذاتية التجهيز على قاعدة معزولة."""

import argparse
import os
import re
import subprocess
import sys
from pathlib import Path


root = Path(__file__).resolve().parents[1]
catalog = {
    "verify_forms_feedback_ui.py": "حفظ القيم، PRG، ومنع الإرسال المزدوج",
    "verify_accessibility_ui.py": "الوصولية والتباين ولوحة المفاتيح",
    "verify_responsive_ui.py": "320/720/768/1440 وأهداف اللمس",
    "verify_print_portal_ui.py": "A4 وبوابة المندوب تحت الاتصال البطيء/المنقطع",
    "verify_workflows_ui.py": "فاتورة/تحصيل مترابط ورحلة تقفيل كاملة",
}

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("--list", action="store_true", help="اعرض الفاحصات دون تشغيل")
parser.add_argument("--only", default="", help="أسماء ملفات مفصولة بفاصلة")
args = parser.parse_args()

if args.list:
    for name, note in catalog.items():
        print(f"{name}\t{note}")
    raise SystemExit(0)

db_name = os.environ.get("ALMOTAWAKEL_TEST_DB", "")
if not re.fullmatch(r"almotawakel_test_[a-z0-9_]{6,48}", db_name):
    raise SystemExit("حارس DSN: اضبط ALMOTAWAKEL_TEST_DB على قاعدة معزولة باسم almotawakel_test_*")
if not os.environ.get("ALMOTAWAKEL_E2E_URL", "").startswith(("http://127.0.0.1:", "http://localhost:")):
    raise SystemExit("حارس الخادم: اضبط ALMOTAWAKEL_E2E_URL على localhost/127.0.0.1 فقط")

selected = [name.strip() for name in args.only.split(",") if name.strip()] or list(catalog)
unknown = [name for name in selected if name not in catalog]
if unknown:
    raise SystemExit("فاحصات غير معروفة: " + ", ".join(unknown))

results: list[tuple[str, int]] = []
for name in selected:
    print(f"\n══ {name} — {catalog[name]} ══", flush=True)
    result = subprocess.run([sys.executable, str(root / "bin" / name)], cwd=root, env=os.environ.copy())
    results.append((name, result.returncode))
    if result.returncode != 0:
        break

print("\n══ ملخص اختبارات المتصفح ══")
for name, code in results:
    print(("✓ نجح  " if code == 0 else "✗ فشل  ") + name)
not_run = selected[len(results):]
for name in not_run:
    print("ـ لم يعمل  " + name)

failed = [name for name, code in results if code != 0]
print(f"الإجمالي: نجح {len(results) - len(failed)} | فشل {len(failed)} | لم يعمل {len(not_run)}")
raise SystemExit(1 if failed else 0)
