# -*- coding: utf-8 -*-
"""
نقل بيانات النظام القديم (old-system-data.xlsx) إلى النظام الاحترافي الجديد
مع الحفاظ الكامل على تصميم النظام الجديد.
"""
import openpyxl
from openpyxl.utils import get_column_letter, column_index_from_string

OLD = "/Users/karimmokhtar/المتوكل/old-system-data.xlsx"
NEW = "/Users/karimmokhtar/المتوكل/المتوكل - نظام إدارة النقل والتخليص.xlsx"
HDR_ROW = 3
DATA_START = 4

def is_err(v):
    return isinstance(v, str) and v.startswith("#")

def clean(v):
    if v is None or is_err(v):
        return None
    if isinstance(v, str) and v.strip() == "":
        return None
    return v

def num(v):
    v = clean(v)
    if v is None:
        return 0.0
    try:
        return float(v)
    except (ValueError, TypeError):
        return 0.0

# ------------------------------------------------------------------
# 1) قراءة النظام القديم
# ------------------------------------------------------------------
old = openpyxl.load_workbook(OLD, data_only=True, read_only=True)

def old_rows(sheet, maxrow):
    ws = old[sheet]
    return list(ws.iter_rows(min_row=2, max_row=maxrow, values_only=True))

# ---- البوالص ----
# خريطة: (فهرس العمود القديم 1-based) -> اسم العمود الجديد
BAW_MAP = [
    (1, "صاحب البيان"), (2, "التاريخ"), (3, "رقم البوليصة"), (4, "التصنيف"),
    (5, "اسم الشركة"), (6, "الجهة"), (7, "رقم الحاوية"), (8, "رقم السيارة"),
    (9, "اسم السائق"), (10, "صاحب السيارة"), (11, "نولون"), (12, "عهدة"),
    (13, "تحويلة"), (14, "خصم"), (15, "بياتة (تحصيل)"), (17, "دفعة"),
    (19, "دور سواق"), (20, "دور تبّاع"), (21, "بياتة (مصروف)"), (22, "مصروف"),
    (23, "كرتات"), (24, "تحيا مصر"), (25, "ثبتان"), (26, "ظ كاوتش"),
    (27, "تصاريح"), (28, "كاوتش"), (32, "جاز"),
]
# العمود 14 = خصم (غير موجود في التصميم الجديد) ، الأعمدة 29,30,31 = "م اخرى/م اخر/م اخرى" تُجمع في "م أخرى"
baw_raw = old_rows("بوالص", 4041)
baw_data = []
khasm_count = 0
khasm_sum = 0.0
for row in baw_raw:
    date = clean(row[1]) if len(row) > 1 else None   # عمود التاريخ (2)
    poliça = clean(row[2]) if len(row) > 2 else None
    if date is None and poliça is None:
        continue
    rec = {}
    for oi, header in BAW_MAP:
        rec[header] = clean(row[oi - 1]) if len(row) >= oi else None
    # جمع بنود "م أخرى" الثلاثة
    rec["م أخرى"] = (num(row[28]) if len(row) > 28 else 0) + \
                    (num(row[29]) if len(row) > 29 else 0) + \
                    (num(row[30]) if len(row) > 30 else 0)
    if rec["م أخرى"] == 0:
        rec["م أخرى"] = None
    # تتبّع الخصم المُهمَل
    kh = num(row[13]) if len(row) > 13 else 0
    if kh:
        khasm_count += 1
        khasm_sum += kh
    baw_data.append(rec)

# ---- المولدات ----
GEN_MAP = [(2, "التاريخ"), (3, "اسم الشركة"), (4, "العنوان"), (5, "رقم الإذن"),
           (6, "عدد المولدات"), (7, "النولون"), (8, "الدفعات")]
def read_gen(sheet, maxrow):
    out = []
    for row in old_rows(sheet, maxrow):
        date = clean(row[1]) if len(row) > 1 else None
        comp = clean(row[2]) if len(row) > 2 else None
        if date is None and comp is None:
            continue
        rec = {h: clean(row[oi - 1]) if len(row) >= oi else None for oi, h in GEN_MAP}
        out.append(rec)
    return out
egy_data = read_gen("مولدات ايجى كول ", 340)
shg_data = read_gen("مولدات الشجيع", 25)

# ---- العملاء والموردين (من صفحة اجمالي) ----
agg = old["اجمالي"]
clients, suppliers = [], []
for row in agg.iter_rows(min_row=2, max_row=60, values_only=True):
    cname = clean(row[0]) if len(row) > 0 else None      # A العملاء
    sname = clean(row[2]) if len(row) > 2 else None      # C الموردين
    sbal  = clean(row[3]) if len(row) > 3 else None      # D رصيد المورد
    if cname:
        clients.append(cname)
    if sname:
        suppliers.append((sname, num(sbal)))
old.close()

print(f"قُرئ من القديم: بوالص={len(baw_data)} | ايجي كول={len(egy_data)} | الشجيع={len(shg_data)} | عملاء={len(clients)} | موردين={len(suppliers)}")
print(f"عمود الخصم المنقول: {khasm_count} صف بإجمالي {khasm_sum:,.0f} ج.م")

# ------------------------------------------------------------------
# 2) فتح النظام الجديد
# ------------------------------------------------------------------
wb = openpyxl.load_workbook(NEW)

def header_map(sheet):
    ws = wb[sheet]
    m = {}
    for c in range(1, ws.max_column + 1):
        v = ws.cell(HDR_ROW, c).value
        if v:
            m[str(v).strip()] = c
    return m

def clear_sample(sheet, upto=12):
    """مسح البيانات النموذجية (القيم الحرفية فقط، مع الإبقاء على المعادلات)"""
    ws = wb[sheet]
    for r in range(DATA_START, upto + 1):
        for c in range(1, ws.max_column + 1):
            cell = ws.cell(r, c)
            v = cell.value
            if v is not None and not (isinstance(v, str) and v.startswith("=")):
                cell.value = None

def write_data(sheet, records):
    ws = wb[sheet]
    hm = header_map(sheet)
    for i, rec in enumerate(records):
        rr = DATA_START + i
        for header, val in rec.items():
            if val is None:
                continue
            c = hm.get(header)
            if c:
                ws.cell(rr, c).value = val

# مسح البيانات النموذجية من كل صفحات الإدخال
for s in ["البوالص", "التخليص", "مولدات ايجي كول", "مولدات الشجيع", "شامل"]:
    clear_sample(s, upto=12)

# كتابة البيانات الحقيقية
write_data("البوالص", baw_data)
write_data("مولدات ايجي كول", egy_data)
write_data("مولدات الشجيع", shg_data)

# ------------------------------------------------------------------
# 3) صفحة الأرصدة (عملاء + موردين)
# ------------------------------------------------------------------
ws_ar = wb["أرصدة العملاء والموردين"]
for i, cname in enumerate(clients):
    ws_ar.cell(DATA_START + i, column_index_from_string("B")).value = cname
for i, (sname, sbal) in enumerate(suppliers):
    ws_ar.cell(DATA_START + i, column_index_from_string("E")).value = sname
    if sbal:
        ws_ar.cell(DATA_START + i, column_index_from_string("F")).value = sbal

# ------------------------------------------------------------------
# 4) تعبئة القوائم المنسدلة بالقيم الحقيقية المميّزة
# ------------------------------------------------------------------
def distinct(records, header):
    seen, out = set(), []
    for r in records:
        v = r.get(header)
        if v and isinstance(v, str):
            v = v.strip()
            if v and v not in seen:
                seen.add(v); out.append(v)
    return sorted(out)

companies = distinct(baw_data, "اسم الشركة")
for r in egy_data + shg_data:
    c = r.get("اسم الشركة")
    if c and isinstance(c, str) and c.strip() and c.strip() not in companies:
        companies.append(c.strip())
companies = sorted(set(companies))
drivers = distinct(baw_data, "اسم السائق")
destinations = distinct(baw_data, "الجهة")
classifications = distinct(baw_data, "التصنيف")
owners = distinct(baw_data, "صاحب السيارة")
client_names = sorted(set([c for c in clients if isinstance(c, str)]))

ws_set = wb["الإعدادات"]
LIST_COLS = {  # عمود الإعدادات -> القائمة
    "D": classifications, "E": companies, "F": destinations,
    "G": drivers, "H": owners, "I": client_names,
}
for col, values in LIST_COLS.items():
    ci = column_index_from_string(col)
    # مسح القديم
    for r in range(4, 4 + 200):
        ws_set.cell(r, ci).value = None
    # كتابة الجديد
    for i, v in enumerate(values[:200]):
        ws_set.cell(4 + i, ci).value = v

print(f"القوائم: شركات={len(companies)} سائقين={len(drivers)} جهات={len(destinations)} تصنيفات={len(classifications)} أصحاب سيارات={len(owners)} عملاء={len(client_names)}")

# ------------------------------------------------------------------
# 5) الحفظ
# ------------------------------------------------------------------
wb.save(NEW)
print("تم النقل والحفظ بنجاح.")
