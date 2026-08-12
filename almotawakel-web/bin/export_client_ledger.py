#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
مستخرِج كشوف حسابات العملاء من ملف الإكسل → ملف JSON للاستيراد (v92).

═══ ليه خطوتين مش خطوة؟ ═══
الاستخراج هنا (بايثون + openpyxl) والكتابة في `bin/import_client_ledger.php`.
السبب مش تفضيل لغة: **الإنتاج ما بيشوفش ملف الإكسل أصلاً** — بيستقبل ملف JSON
متحقَّق منه ومختوم ببصمة. وكده الحساب اللي بيتكتب في القاعدة يبقى **مقروءًا
بالعين** قبل ما يتنفّذ، بدل «صدّق إن القارئ فهم الخلية صح».

═══ شكل المصدر ═══
شيت لكل عميل، وكل شيت أعمدته (بتتفاوت في الترتيب والتسمية فبنقراها بالعنوان
لا بموضع العمود):
    فاتورة · م · التاريخ · اسم الشركه · العنوان · رقم الاذن · عدد الحاويات ·
    النولون · قيمة مضافة · خصم · الايصالات · اجمالي الفاتوره · الدفعات · اجمالي الحساب

والقاعدة اللي اتحققت على **٩٢٠ من ٩٢١** سطر:
    اجمالي الفاتوره = النولون + قيمة مضافة + الايصالات − الخصم

⚠️ **السطر الأول (صف ٢) لو فيه رصيد بلا فاتورة ولا دفعة فهو رصيد مرحّل من ٢٠٢٥**
   لا سطر حركة. تسعة عملاء عندهم كده بإجمالي ٢٤٧,٨٥٩ — وتجاهُله بيخلّي رصيدهم غلط.

⚠️ **الرقم بيتقرا من القيمة المخزَّنة لا من المعادلة** (`data_only=True`) — يعني
   لازم الملف يكون **اتفتح واتحفظ من إكسل** آخر مرة، وإلا القيم المحسوبة بتيجي
   `None`. السكربت بيقف بصوت عالي لو لقى عمودًا محسوبًا فاضيًا بالكامل.

التشغيل:
    python3 bin/export_client_ledger.py "2026 (2).xlsx" -o storage/imports/client_ledger.json
"""
from __future__ import annotations

import argparse
import datetime
import hashlib
import json
import re
import sys
from collections import OrderedDict

try:
    import openpyxl
except ImportError:
    sys.exit("محتاج openpyxl:  pip3 install openpyxl")

# شيتات مش حسابات عملاء — بتتخطّى بالاسم لا بالشكل.
SKIP_SHEETS = {"بوالص", "اجمالي", "اجمالي بوالص", "cib"}

# مرادفات عناوين الأعمدة → الاسم القانوني الواحد.
HEADER_ALIAS = {
    "رقم الفاتورة": "فاتورة",
    "خصم 3%": "خصم",
    "خصم أ ت ص": "خصم",
    "عدد المولدات": "عدد الحاويات",
    "اجمالي": "اجمالي الحساب",
}

# الأعمدة اللي لازم تكون موجودة في أي شيت عميل.
#
# ⚠️ «اجمالي الفاتوره» **مش** في القايمة عن قصد: شيت «مولدات» حساب ساكن برصيد
#    مرحّل بلا أي حركة، فمالوش عمود فواتير أصلاً. والحارس اللي بيمسك الشيت
#    اللي **اتسمّى عموده غلط** مش وجود العمود — ده **مطابقة الرصيد الختامي**
#    في آخر الدالة: شيت فيه فواتير وعموده ضاع بيدّي فرقًا بحجم فواتيره كلها.
REQUIRED = ("التاريخ", "الدفعات", "اجمالي الحساب")

# فرق مقبول في تقريب القرش قبل ما نعتبره خطأ حقيقي.
EPS = 0.02

# تاريخ الرصيد المرحّل — آخر يوم في السنة السابقة للحركة.
OPENING_DATE = "2025-12-31"


def norm(v):
    return v.strip() if isinstance(v, str) else v


def num(v) -> float:
    """رقم أو صفر — النص والفراغ والتاريخ كلهم صفر، بلا استثناء صامت."""
    return float(v) if isinstance(v, (int, float)) and not isinstance(v, bool) else 0.0


def parse_date(v):
    """تاريخ الخلية → ISO. بيقبل تاريخ إكسل حقيقي أو نص d/m/Y (المصدر فيه الاتنين)."""
    if isinstance(v, datetime.datetime):
        return v.date().isoformat()
    if isinstance(v, datetime.date):
        return v.isoformat()
    if isinstance(v, str):
        m = re.match(r"^\s*(\d{1,2})/(\d{1,2})/(\d{4})", v)
        if m:
            try:
                return datetime.date(int(m[3]), int(m[2]), int(m[1])).isoformat()
            except ValueError:
                return None
    return None


def read_sheet(ws):
    """صفوف الشيت كقواميس بالاسم القانوني — مع رقم الصف الأصلي (بصمة الاستيراد)."""
    data = list(ws.iter_rows(values_only=True))
    if not data:
        return [], {}
    header = [HEADER_ALIAS.get(norm(c), norm(c)) for c in data[0]]
    idx = {h: i for i, h in enumerate(header) if h}
    out = []
    for row_no, row in enumerate(data[1:], start=2):
        if row is None or all(c is None or c == "" for c in row):
            continue

        def cell(key):
            i = idx.get(key)
            return row[i] if i is not None and i < len(row) else None

        out.append({
            "row": row_no,
            "date": parse_date(cell("التاريخ")),
            "raw_date": str(cell("التاريخ")) if cell("التاريخ") is not None else "",
            "company": norm(cell("اسم الشركه")) or "",
            "destination": norm(cell("العنوان")) or "",
            "permit_no": str(norm(cell("رقم الاذن")) or "").strip(),
            "containers": str(norm(cell("عدد الحاويات")) or "").strip(),
            "invoice_ref": str(norm(cell("فاتورة")) or "").strip(),
            "freight": num(cell("النولون")),
            "vat": num(cell("قيمة مضافة")),
            "discount": num(cell("خصم")),
            "receipts": num(cell("الايصالات")),
            "total": num(cell("اجمالي الفاتوره")),
            "payment": num(cell("الدفعات")),
            "balance": cell("اجمالي الحساب"),
        })
    return out, idx


def build(path: str):
    wb = openpyxl.load_workbook(path, data_only=True, read_only=True)
    clients, problems = [], []
    grand = {"invoices": 0.0, "payments": 0.0, "opening": 0.0, "closing": 0.0}

    for name in wb.sheetnames:
        if name in SKIP_SHEETS:
            continue
        rows, idx = read_sheet(wb[name])
        sheet = name.strip()
        missing = [c for c in REQUIRED if c not in idx]
        if missing:
            problems.append({"client": sheet, "kind": "أعمدة ناقصة", "detail": "، ".join(missing)})
            continue
        if not rows:
            continue

        # ① الرصيد المرحّل: أول سطر فيه رصيد بلا فاتورة ولا دفعة.
        opening = 0.0
        first = rows[0]
        if abs(first["total"]) < EPS and abs(first["payment"]) < EPS \
                and isinstance(first["balance"], (int, float)):
            opening = round(float(first["balance"]), 2)

        # ⚠️ **وراثة التاريخ**: الكشف مرتَّب زمنيًّا، والسطر بلا تاريخ (٨ سطور:
        #    تحصيلات مقسّمة + سنة مكتوبة غلط «8/1/206») واقع **بين** سطرين
        #    مؤرَّخين. فبياخد تاريخ آخر سطر مؤرَّخ قبله، وبيتعلّم `date_inferred`
        #    فيفضل باين في المراجعة. البديل — سطر بلا تاريخ — بيطلع في أول
        #    الكشف أو آخره فيبوّظ الرصيد التراكمي المعروض.
        carried = None
        for r in rows:
            if r["date"]:
                carried = r["date"]
            elif abs(r["total"]) >= EPS or abs(r["payment"]) >= EPS:
                r["date"] = carried
                r["date_inferred"] = True
                if carried is None:
                    problems.append({"client": sheet, "kind": "سطر بلا تاريخ ولا سابق مؤرَّخ",
                                     "detail": f"صف {r['row']}"})
                else:
                    problems.append({
                        "client": sheet, "kind": "تاريخ موروث من السطر السابق",
                        "detail": f"صف {r['row']} ({r['raw_date'] or 'فاضي'}) ← {carried}",
                    })

        docs, pays = [], []
        for r in rows:
            # ⚠️ اسم الشركة بيتسجّل **بس** لو السطر شحنة حقيقية (له رقم إذن).
            #    الشيت بيستخدم الخانة دي كمان كتعليق («غرامة 310 دولار» ·
            #    «من دفعة 180 الف» · «خصم»)، ولو عدّت زي ما هي كانت هتتولّد
            #    شركات بالأسماء دي في دليل الأطراف بالسكوت.
            company = r["company"] if r["permit_no"] else ""
            remark = "" if r["permit_no"] else r["company"]
            # ② الفاتورة
            if abs(r["total"]) >= EPS:
                parts = round(r["freight"] + r["vat"] + r["receipts"] - r["discount"], 2)
                if abs(parts - r["total"]) > EPS:
                    # مجموع البنود مش بيدّي الإجمالي — بنسجّل الإجمالي كبند تسوية
                    # واحد بدل ما نخترع أرقامًا، وبنبلّغ عنه في التقرير.
                    problems.append({
                        "client": sheet, "kind": "بنود لا تجمع الإجمالي",
                        "detail": f"صف {r['row']} — بنود {parts:,.2f} × إجمالي {r['total']:,.2f}",
                    })
                    lines = [{"label": "قيمة الفاتورة", "amount": round(r["total"], 2), "freight": 0}]
                    freight = vat = 0.0
                else:
                    lines, freight, vat = [], round(r["freight"], 2), round(r["vat"], 2)
                    if abs(freight) >= EPS:
                        lines.append({"label": "النولون", "amount": freight, "freight": 1})
                    if abs(r["receipts"]) >= EPS:
                        lines.append({"label": "الإيصالات", "amount": round(r["receipts"], 2), "freight": 0})
                    if abs(r["discount"]) >= EPS:
                        lines.append({"label": "خصم", "amount": -round(r["discount"], 2), "freight": 0})
                    if not lines:
                        lines = [{"label": "قيمة الفاتورة", "amount": round(r["total"], 2), "freight": 0}]

                docs.append({
                    "key": f"{sheet}#{r['row']}",
                    "date": r["date"],
                    "date_inferred": bool(r.get("date_inferred")),
                    "permit_no": r["permit_no"],
                    "company": company,
                    "note": remark,
                    "destination": r["destination"],
                    "containers": r["containers"],
                    "invoice_ref": r["invoice_ref"],
                    "freight": freight,
                    "vat_amount": vat,
                    "subtotal": round(r["total"] - vat, 2),
                    "total": round(r["total"], 2),
                    "lines": lines,
                })

            # ③ التحصيل — سطر واحد ممكن يحمل فاتورة وتحصيل مع بعض، فمش elif.
            if abs(r["payment"]) >= EPS:
                pays.append({
                    "key": f"{sheet}#{r['row']}",
                    "date": r["date"],
                    "date_inferred": bool(r.get("date_inferred")),
                    "amount": round(r["payment"], 2),
                    "note": remark or company,
                })

        # ④ الرصيد الختامي زي ما الشيت نفسه بيقوله = مرجع التحقّق بعد الكتابة.
        closing = 0.0
        for r in rows:
            if isinstance(r["balance"], (int, float)):
                closing = round(float(r["balance"]), 2)

        computed = round(opening + sum(d["total"] for d in docs) - sum(p["amount"] for p in pays), 2)
        residue = round(closing - computed, 2)
        if abs(residue) > 1.0:
            problems.append({
                "client": sheet, "kind": "رصيد الشيت لا يطابق حركته",
                "detail": f"الشيت {closing:,.2f} × المحسوب {computed:,.2f} — فرق {residue:,.2f}",
            })

        clients.append(OrderedDict([
            ("client", sheet),
            ("opening", opening),
            ("opening_date", OPENING_DATE),
            ("invoices_total", round(sum(d["total"] for d in docs), 2)),
            ("payments_total", round(sum(p["amount"] for p in pays), 2)),
            ("sheet_closing", closing),
            ("computed_closing", computed),
            ("residue", residue),
            ("documents", docs),
            ("payments", pays),
        ]))
        grand["invoices"] += sum(d["total"] for d in docs)
        grand["payments"] += sum(p["amount"] for p in pays)
        grand["opening"] += opening
        grand["closing"] += closing

    payload = OrderedDict([
        ("format", "almutawakkil.client_ledger/1"),
        ("source_file", path.rsplit("/", 1)[-1]),
        ("source_sha256", hashlib.sha256(open(path, "rb").read()).hexdigest()),
        ("clients_count", len(clients)),
        ("documents_count", sum(len(c["documents"]) for c in clients)),
        ("payments_count", sum(len(c["payments"]) for c in clients)),
        ("totals", {k: round(v, 2) for k, v in grand.items()}),
        ("problems", problems),
        ("clients", clients),
    ])
    return payload


def main():
    ap = argparse.ArgumentParser(description="استخراج كشوف حسابات العملاء من الإكسل")
    ap.add_argument("xlsx")
    ap.add_argument("-o", "--out", default="storage/imports/client_ledger.json")
    args = ap.parse_args()

    p = build(args.xlsx)

    import os
    os.makedirs(os.path.dirname(args.out) or ".", exist_ok=True)
    with open(args.out, "w", encoding="utf-8") as fh:
        json.dump(p, fh, ensure_ascii=False, indent=1)

    t = p["totals"]
    print(f"العملاء            : {p['clients_count']}")
    print(f"الفواتير           : {p['documents_count']:>6}  =  {t['invoices']:>15,.2f}")
    print(f"التحصيلات          : {p['payments_count']:>6}  =  {t['payments']:>15,.2f}")
    print(f"الأرصدة المرحّلة   :         {t['opening']:>15,.2f}")
    print(f"الرصيد الختامي     :         {t['closing']:>15,.2f}")
    print(f"المحسوب            :         "
          f"{t['opening'] + t['invoices'] - t['payments']:>15,.2f}")
    print(f"\nملاحظات محتاجة مراجعة: {len(p['problems'])}")
    for pr in p["problems"]:
        print(f"   • {pr['client']}: {pr['kind']} — {pr['detail']}")
    print(f"\n✓ اتكتب: {args.out}")
    print(f"  بصمة المصدر: {p['source_sha256'][:16]}…")


if __name__ == "__main__":
    main()
