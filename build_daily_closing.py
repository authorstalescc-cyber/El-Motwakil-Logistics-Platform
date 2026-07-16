# -*- coding: utf-8 -*-
"""
نظام المتوكل — ملف تقفيل يومية العمل (العُهدة الكاش)
ملف مستقل مربوط بروابط حيّة (External Links) بالملف الأساسي:
  «المتوكل - نظام إدارة النقل والتخليص.xlsx»
يسحب القوائم (الشركات / الجهات / ملاك السيارات) تلقائياً من صفحة الإعدادات في الأساسي.
البناء: openpyxl + حقن External Link يدوياً (بصيغة [1] + كاش القيم).
"""
import os
from openpyxl import Workbook, load_workbook
from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
from openpyxl.utils import get_column_letter
from openpyxl.worksheet.datavalidation import DataValidation
from openpyxl.formatting.rule import CellIsRule
from openpyxl.workbook.defined_name import DefinedName
from openpyxl.worksheet.properties import PageSetupProperties
from openpyxl.worksheet.formula import ArrayFormula

# ============================ الهوية واللون (مطابقة للنظام الأساسي) ============================
NAVY="FF15355B"; NAVY_DARK="FF0E2440"; NAVY_MID="FF1E4B7A"
GOLD="FFC9A227"; GOLD_LIGHT="FFF3E9C6"
GREEN="FF1E6E3C"; GREEN_LT="FFE3F1E7"; RED="FF9B2C2C"; RED_LT="FFF6E4E4"
TEAL="FF14746F"; PURPLE="FF5B3E7A"; WHITE="FFFFFFFF"; INK="FF1A2433"; GREY_TXT="FF5A6472"
INPUT_FILL="FFFFFDF3"; CALC_FILL="FFEDF2F8"; ZEBRA="FFF7F9FB"; GRID="FFD5DBE2"
SECTION_BG="FFEAF0F6"; BANNER_BG=NAVY
FONT_NAME="Arial"

MONEY='#,##0.00 "ج.م"'; MONEY0='#,##0 "ج.م"'; DATEF='DD/MM/YYYY'; INTF='#,##0'

DIR = "/Users/karimmokhtar/المتوكل"
MAIN_FILE = "المتوكل - نظام إدارة النقل والتخليص.xlsx"
MAIN_PATH = os.path.join(DIR, MAIN_FILE)
OUT = os.path.join(DIR, "المتوكل - تقفيل اليومية.xlsx")

thin = Side(style="thin", color=GRID)
box_thin = Border(left=thin, right=thin, top=thin, bottom=thin)

# نطاقات القوائم في الأساسي
LIST_START, LIST_END = 4, 203          # صفوف القوائم في صفحة الإعدادات بالأساسي
SET_SHEET = "الإعدادات"                 # اسم صفحة الإعدادات في الأساسي
# أعمدة القوائم المشتركة في الأساسي:  E=الشركات ، F=الجهات ، H=أصحاب السيارات
SHARED = [("E", "الشركات"), ("F", "الجهات"), ("H", "أصحاب السيارات")]

# ============================ قراءة القيم الحالية من الأساسي (للكاش) ============================
mwb = load_workbook(MAIN_PATH, data_only=True, read_only=True)
mset = mwb[SET_SHEET]
cache_vals = {}   # col_letter -> {row:value}
for col, _ in SHARED:
    d = {}
    for r in range(LIST_START, LIST_END+1):
        v = mset[f"{col}{r}"].value
        if v not in (None, ""):
            d[r] = str(v)
    cache_vals[col] = d
mwb.close()

# ============================ دوال مساعدة عامة ============================
def sref(s): return "'" + s + "'"

def set_widths(ws, widths):
    for i, w in enumerate(widths, start=1):
        ws.column_dimensions[get_column_letter(i)].width = w

def banner(ws, ncols, title, subtitle=""):
    last = get_column_letter(ncols)
    ws.merge_cells(f"A1:{last}1"); ws.merge_cells(f"A2:{last}2")
    c = ws["A1"]; c.value = "شركة المتوكل  —  " + title
    c.font = Font(name=FONT_NAME, size=20, bold=True, color=GOLD)
    c.alignment = Alignment(horizontal="center", vertical="center")
    s = ws["A2"]; s.value = subtitle if subtitle else "لخدمات النقل والتخليص الجمركي"
    s.font = Font(name=FONT_NAME, size=11, color=WHITE)
    s.alignment = Alignment(horizontal="center", vertical="center")
    ws.row_dimensions[1].height = 34; ws.row_dimensions[2].height = 20
    for r in (1, 2):
        for col in range(1, ncols+1):
            ws.cell(r, col).fill = PatternFill("solid", fgColor=(BANNER_BG if r == 1 else NAVY_MID))

def fill_block(ws, r1, c1, r2, c2, color):
    for r in range(r1, r2+1):
        for c in range(c1, c2+1):
            ws.cell(r, c).fill = PatternFill("solid", fgColor=color)

def border_block(ws, r1, c1, r2, c2, side):
    for r in range(r1, r2+1):
        for c in range(c1, c2+1):
            ws.cell(r, c).border = Border(left=side, right=side, top=side, bottom=side)

wb = Workbook()

# أسماء الصفحات
F_FORM = "تقفيل اليوم"
F_REG  = "سجل اليومية"
F_TRIP = "العهد والرحلات"
F_RCPT = "الإيصالات"
F_SET  = "الإعدادات"
F_GUIDE= "الدليل"

# ==========================================================================
#                    (1) صفحة الإعدادات (قوائم محلية + نسخة من قوائم الأساسي)
# ==========================================================================
ws_set = wb.active; ws_set.title = F_SET
ws_set.sheet_view.rightToLeft = True; ws_set.sheet_view.showGridLines = False
banner(ws_set, 9, "الإعدادات والقوائم", "قوائم محلية + نسخة محدَّثة من قوائم الملف الأساسي (تُزامَن بسكربت المزامنة)")

SET_ROWS = 200
S_START = 4
S_END = S_START + SET_ROWS - 1

def list_header(col_letter, text, color):
    c = ws_set[f"{col_letter}3"]; c.value = text
    c.font = Font(name=FONT_NAME, bold=True, color=WHITE)
    c.fill = PatternFill("solid", fgColor=color)
    c.alignment = Alignment(horizontal="center", vertical="center")
    c.border = box_thin

def style_list_col(col_letter, kind):
    for r in range(S_START, S_END+1):
        c = ws_set[f"{col_letter}{r}"]
        c.fill = PatternFill("solid", fgColor=(INPUT_FILL if kind == "input" else CALC_FILL))
        c.border = box_thin
        c.font = Font(name=FONT_NAME, size=10, color=INK, italic=(kind == "link"))
        c.alignment = Alignment(horizontal="center", vertical="center")

# --- إعداد عام: رصيد عهدة افتتاحي ---
ws_set["A3"] = "الإعداد"; ws_set["B3"] = "القيمة"
for cc in (ws_set["A3"], ws_set["B3"]):
    cc.font = Font(name=FONT_NAME, bold=True, color=WHITE)
    cc.fill = PatternFill("solid", fgColor=NAVY)
    cc.alignment = Alignment(horizontal="center", vertical="center"); cc.border = box_thin
ws_set["A4"] = "رصيد عهدة افتتاحي (أول يوم)"
ws_set["A4"].font = Font(name=FONT_NAME, bold=True, color=INK)
ws_set["A4"].fill = PatternFill("solid", fgColor=SECTION_BG)
ws_set["A4"].alignment = Alignment(horizontal="right", vertical="center", indent=1)
ws_set["A4"].border = box_thin
ws_set["B4"] = 0
ws_set["B4"].font = Font(name=FONT_NAME, bold=True, color=GREEN)
ws_set["B4"].fill = PatternFill("solid", fgColor=INPUT_FILL)
ws_set["B4"].number_format = MONEY0
ws_set["B4"].alignment = Alignment(horizontal="center", vertical="center"); ws_set["B4"].border = box_thin

# --- قوائم محلية (تُحرَّر يدوياً) ---
LOCAL = {
    "D": ("شركة الشحن", GOLD, [
        "ميرسك","MSC","CMA CGM","هاباج لويد","ONE","إيفرغرين","كوسكو",
        "يانغ مينغ","هيونداي","زيم","OOCL","سيلاند","الوطنية"]),
    "E": ("توكيل", GOLD, []),
    "F": ("الميناء", GOLD, [
        "الإسكندرية","الدخيلة","دمياط","بورسعيد","غرب بورسعيد","شرق بورسعيد",
        "السخنة","سفاجا","نويبع","العريش","6 أكتوبر الجاف","القاهرة الجاف"]),
}
for col, (name, color, seed) in LOCAL.items():
    list_header(col, name, color); style_list_col(col, "input")
    for i, v in enumerate(seed):
        ws_set[f"{col}{S_START+i}"] = v

# --- نسخة من قوائم الأساسي (قيم مدمجة، تُزامَن بسكربت المزامنة sync_lists.py) ---
MIRROR = {"H": ("الشركات (من الأساسي)", "E"),
          "I": ("الجهات (من الأساسي)", "F"),
          "J": ("ملاك السيارات (من الأساسي)", "H")}
for mcol, (label, srccol) in MIRROR.items():
    list_header(mcol, label, TEAL); style_list_col(mcol, "input")
    for r in range(S_START, S_END+1):
        # r في النسخة يطابق الصف نفسه في الأساسي (يبدأ من 4)
        v = cache_vals[srccol].get(r)
        if v is not None:
            ws_set[f"{mcol}{r}"] = v

set_widths(ws_set, [26, 14, 2, 18, 16, 16, 2, 20, 20, 22])
ws_set.column_dimensions["C"].width = 2
ws_set.column_dimensions["G"].width = 2

# الأسماء المعرّفة للقوائم
def dn(name, ref): wb.defined_names.add(DefinedName(name, attr_text=ref))
dn("رصيد_افتتاحي", f"{sref(F_SET)}!$B$4")
NAMED = {
    "قائمة_شركات_الشحن": "D", "قائمة_التوكيلات": "E", "قائمة_الموانئ": "F",
    "قائمة_الشركات": "H", "قائمة_الجهات": "I", "قائمة_الملاك": "J",
}
for nm, col in NAMED.items():
    dn(nm, f"{sref(F_SET)}!${col}${S_START}:${col}${S_END}")

def add_dv(ws, col_letter, named, r1, r2):
    dv = DataValidation(type="list", formula1=f"={named}", allow_blank=True)
    dv.errorStyle = "warning"       # يسمح بالكتابة لو الرابط غير محدَّث
    dv.error = "غير موجود في القائمة — تقدر تكمل، أو أضِفه في صفحة الإعدادات"
    dv.errorTitle = "تنبيه"; dv.prompt = "اختر من القائمة"; dv.promptTitle = ""
    ws.add_data_validation(dv); dv.add(f"{col_letter}{r1}:{col_letter}{r2}")

# ==========================================================================
#            محرّك بناء صفحة سجل (رؤوس + خلايا مُنسّقة)
# ==========================================================================
HDR_ROW = 3
def style_headers(ws, headers, colors):
    for i, (h, gc) in enumerate(zip(headers, colors), start=1):
        c = ws.cell(HDR_ROW, i, h)
        c.font = Font(name=FONT_NAME, size=10, bold=True, color=WHITE)
        c.alignment = Alignment(horizontal="center", vertical="center", wrap_text=True)
        c.fill = PatternFill("solid", fgColor=gc)
        c.border = Border(left=Side(style="thin", color=WHITE),
                          right=Side(style="thin", color=WHITE),
                          top=thin, bottom=Side(style="medium", color=GOLD))
    ws.row_dimensions[HDR_ROW].height = 38

def style_cell(cell, kind, numfmt, zebra=False):
    fill = INPUT_FILL if kind == "input" else CALC_FILL
    if zebra and kind == "input": fill = ZEBRA
    cell.fill = PatternFill("solid", fgColor=fill)
    cell.border = box_thin
    cell.font = Font(name=FONT_NAME, size=10, color=INK, italic=(kind != "input"))
    cell.alignment = Alignment(horizontal="center", vertical="center")
    if numfmt: cell.number_format = numfmt

def finalize(ws, ncols, data_start, data_end):
    ws.sheet_view.rightToLeft = True
    ws.freeze_panes = f"A{data_start}"
    ws.auto_filter.ref = f"A{HDR_ROW}:{get_column_letter(ncols)}{data_end}"
    ws.sheet_view.showGridLines = False
    ws.print_options.horizontalCentered = True
    ws.page_setup.orientation = "landscape"
    ws.page_setup.fitToWidth = 1; ws.page_setup.fitToHeight = 0
    ws.sheet_properties.pageSetUpPr = PageSetupProperties(fitToPage=True)

def totals_row(ws, ncols, sum_cols, data_start, data_end, label_col, label):
    r = data_end + 1
    for col in range(1, ncols+1):
        c = ws.cell(r, col)
        c.fill = PatternFill("solid", fgColor=NAVY)
        c.font = Font(name=FONT_NAME, size=10, bold=True, color=GOLD)
        c.border = Border(top=Side(style="double", color=GOLD))
        c.alignment = Alignment(horizontal="center", vertical="center")
    ws.cell(r, label_col, label)
    for (col, numfmt) in sum_cols:
        L = get_column_letter(col)
        cc = ws.cell(r, col, f"=SUM(${L}${data_start}:${L}${data_end})")
        cc.number_format = numfmt
    return r

TDATA = 2000
T_START = 4
T_END = T_START + TDATA - 1

# ==========================================================================
#                    (2) صفحة العهد والرحلات (سجل مستمر)
# ==========================================================================
ws_t = wb.create_sheet(F_TRIP)
ws_t.sheet_view.rightToLeft = True; ws_t.sheet_view.showGridLines = False
trip_headers = ["م","التاريخ","عدد الحاويات","شركة الشحن","اسم الشركة","الجهة",
                "توكيل","الميناء","مالك السيارة","العهد"]
trip_colors  = [NAVY,NAVY,NAVY_MID,NAVY_MID,NAVY,NAVY,NAVY,NAVY,NAVY_MID,GOLD]
banner(ws_t, len(trip_headers), F_TRIP, "سجل مستمر لكل الرحلات والعُهد — يوماً بعد يوم")
style_headers(ws_t, trip_headers, trip_colors)
# التاريخ عمود B (2) هو المفتاح
for r in range(T_START, T_END+1):
    zebra = (r % 2 == 0)
    style_cell(ws_t.cell(r,1), "formula", INTF, zebra)   # م
    ws_t.cell(r,1).value = f'=IF($B{r}="","",COUNTA($B${T_START}:$B{r}))'
    style_cell(ws_t.cell(r,2), "input", DATEF, zebra)    # التاريخ
    style_cell(ws_t.cell(r,3), "input", INTF, zebra)     # عدد الحاويات
    for cc in range(4,10):
        style_cell(ws_t.cell(r,cc), "input", None, zebra)
    style_cell(ws_t.cell(r,10), "input", MONEY, zebra)   # العهد
set_widths(ws_t, [5,13,10,15,20,16,14,14,20,13])
totals_row(ws_t, len(trip_headers), [(3,INTF),(10,MONEY)], T_START, T_END, 2, "الإجماليات")
# قوائم منسدلة
add_dv(ws_t, "D", "قائمة_شركات_الشحن", T_START, T_END)
add_dv(ws_t, "E", "قائمة_الشركات",     T_START, T_END)
add_dv(ws_t, "F", "قائمة_الجهات",       T_START, T_END)
add_dv(ws_t, "G", "قائمة_التوكيلات",    T_START, T_END)
add_dv(ws_t, "H", "قائمة_الموانئ",      T_START, T_END)
add_dv(ws_t, "I", "قائمة_الملاك",       T_START, T_END)
finalize(ws_t, len(trip_headers), T_START, T_END)

# ==========================================================================
#                    (3) صفحة الإيصالات (سجل مستمر)
# ==========================================================================
ws_c = wb.create_sheet(F_RCPT)
ws_c.sheet_view.rightToLeft = True; ws_c.sheet_view.showGridLines = False
rc_headers = ["م","التاريخ","المبلغ","البيان / الجهة"]
rc_colors  = [NAVY,NAVY,GOLD,NAVY_MID]
banner(ws_c, 6, F_RCPT, "سجل مستمر لكل الإيصالات — يوماً بعد يوم")
style_headers(ws_c, rc_headers, rc_colors)
for r in range(T_START, T_END+1):
    zebra = (r % 2 == 0)
    style_cell(ws_c.cell(r,1), "formula", INTF, zebra)
    ws_c.cell(r,1).value = f'=IF($B{r}="","",COUNTA($B${T_START}:$B{r}))'
    style_cell(ws_c.cell(r,2), "input", DATEF, zebra)
    style_cell(ws_c.cell(r,3), "input", MONEY, zebra)
    style_cell(ws_c.cell(r,4), "input", None, zebra)
    # دمج عمودين إضافيين لعرض البيان بشكل مريح
for r in range(T_START, T_END+1):
    style_cell(ws_c.cell(r,5), "input", None, (r%2==0))
    style_cell(ws_c.cell(r,6), "input", None, (r%2==0))
set_widths(ws_c, [6,14,15,26,14,14])
totals_row(ws_c, 6, [(3,MONEY)], T_START, T_END, 2, "الإجماليات")
finalize(ws_c, 4, T_START, T_END)
ws_c.auto_filter.ref = f"A{HDR_ROW}:D{T_END}"

# ==========================================================================
#                    (4) صفحة سجل اليومية (صف لكل يوم — الترحيل)
# ==========================================================================
ws_r = wb.create_sheet(F_REG)
ws_r.sheet_view.rightToLeft = True; ws_r.sheet_view.showGridLines = False
reg_headers = ["م","التاريخ","باقي عهدة سابق","العهدة المستلمة","إجمالي العهدة المتاحة",
               "إجمالي العهد (الرحلات)","إجمالي الإيصالات","مصروفات أخرى",
               "إجمالي المصروف","رصيد عهد مُرحَّل لليوم التالي"]
reg_colors  = [NAVY,NAVY,PURPLE,GREEN,TEAL,RED,RED,RED,RED,GOLD]
banner(ws_r, len(reg_headers), F_REG, "تقفيل كل يوم في صف — الرصيد يترحّل تلقائياً لليوم التالي")
style_headers(ws_r, reg_headers, reg_colors)

RDATA = 500
R_START = 4
R_END = R_START + RDATA - 1
tdate = f"{sref(F_TRIP)}!$B${T_START}:$B${T_END}"
tamt  = f"{sref(F_TRIP)}!$J${T_START}:$J${T_END}"
tcnt  = f"{sref(F_TRIP)}!$C${T_START}:$C${T_END}"
cdate = f"{sref(F_RCPT)}!$B${T_START}:$B${T_END}"
camt  = f"{sref(F_RCPT)}!$C${T_START}:$C${T_END}"

for r in range(R_START, R_END+1):
    zebra = (r % 2 == 0)
    # م
    style_cell(ws_r.cell(r,1), "formula", INTF, zebra)
    ws_r.cell(r,1).value = f'=IF($B{r}="","",COUNTA($B${R_START}:$B{r}))'
    # التاريخ (input)
    style_cell(ws_r.cell(r,2), "input", DATEF, zebra)
    # باقي عهدة سابق (= رصيد مرحّل من الصف السابق ؛ أول صف = الرصيد الافتتاحي)
    style_cell(ws_r.cell(r,3), "formula", MONEY, zebra)
    if r == R_START:
        ws_r.cell(r,3).value = f'=IF($B{r}="","",رصيد_افتتاحي)'
    else:
        ws_r.cell(r,3).value = f'=IF($B{r}="","",N($J{r-1}))'
    # العهدة المستلمة (input)
    style_cell(ws_r.cell(r,4), "input", MONEY, zebra)
    # إجمالي العهدة المتاحة = باقي سابق + المستلمة
    style_cell(ws_r.cell(r,5), "formula", MONEY, zebra)
    ws_r.cell(r,5).value = f'=IF($B{r}="","",N($C{r})+N($D{r}))'
    # إجمالي العهد (الرحلات) = SUMIFS بالتاريخ
    style_cell(ws_r.cell(r,6), "formula", MONEY, zebra)
    ws_r.cell(r,6).value = f'=IF($B{r}="","",SUMIFS({tamt},{tdate},$B{r}))'
    # إجمالي الإيصالات = SUMIFS بالتاريخ
    style_cell(ws_r.cell(r,7), "formula", MONEY, zebra)
    ws_r.cell(r,7).value = f'=IF($B{r}="","",SUMIFS({camt},{cdate},$B{r}))'
    # مصروفات أخرى (input)
    style_cell(ws_r.cell(r,8), "input", MONEY, zebra)
    # إجمالي المصروف = العهد + الإيصالات + مصروفات أخرى
    style_cell(ws_r.cell(r,9), "formula", MONEY, zebra)
    ws_r.cell(r,9).value = f'=IF($B{r}="","",N($F{r})+N($G{r})+N($H{r}))'
    # رصيد مُرحّل = المتاح − المصروف
    style_cell(ws_r.cell(r,10), "formula", MONEY, zebra)
    ws_r.cell(r,10).value = f'=IF($B{r}="","",N($E{r})-N($I{r}))'

set_widths(ws_r, [5,13,15,14,16,15,14,13,15,18])
totals_row(ws_r, len(reg_headers), [(4,MONEY),(6,MONEY),(7,MONEY),(8,MONEY),(9,MONEY)],
           R_START, R_END, 2, "الإجماليات")
# تنسيق شرطي: رصيد مُرحّل سالب = أحمر (عجز عهدة)
mr = get_column_letter(10)
ws_r.conditional_formatting.add(
    f"{mr}{R_START}:{mr}{R_END}",
    CellIsRule(operator="lessThan", formula=["0"],
               fill=PatternFill("solid", fgColor=RED_LT), font=Font(color=RED, bold=True)))
finalize(ws_r, len(reg_headers), R_START, R_END)

# ==========================================================================
#                    (5) صفحة تقفيل اليوم (فورم للطباعة)
# ==========================================================================
ws_f = wb.create_sheet(F_FORM)
ws_f.sheet_view.rightToLeft = True; ws_f.sheet_view.showGridLines = False
NCF = 9
banner(ws_f, NCF, "تقفيل يومية العمل", "اكتب التاريخ في الخانة الصفراء ← يظهر تقفيل اليوم كامل جاهز للطباعة")
set_widths(ws_f, [11,13,20,16,13,13,20,13,4])
# أعمدة مخفية للمساعدة (فهارس الصفوف المطابقة للتاريخ)
ws_f.column_dimensions["K"].hidden = True
ws_f.column_dimensions["L"].hidden = True

DATE_CELL = "$C$4"   # خانة التاريخ

def label_cell(coord, text, accent=NAVY, size=11):
    c = ws_f[coord]; c.value = text
    c.font = Font(name=FONT_NAME, bold=True, color=WHITE, size=size)
    c.fill = PatternFill("solid", fgColor=accent)
    c.alignment = Alignment(horizontal="center", vertical="center"); c.border = box_thin

def val_cell(coord, value, numfmt, accent=INK, fill=WHITE, size=12, is_input=False):
    c = ws_f[coord]; c.value = value
    c.number_format = numfmt
    c.font = Font(name=FONT_NAME, bold=True, color=accent, size=size)
    c.fill = PatternFill("solid", fgColor=(INPUT_FILL if is_input else fill))
    c.alignment = Alignment(horizontal="center", vertical="center"); c.border = box_thin

# --- شريط التاريخ ---
ws_f.merge_cells("D4:E4"); label_cell("D4", "التاريخ", GOLD, 12)
# دمج خانة التاريخ لتكون واضحة
ws_f.merge_cells("B4:C4")
val_cell("B4", "=TODAY()", DATEF, GREEN, size=14, is_input=True)  # الخانة الصفراء
ws_f.row_dimensions[4].height = 26
# ملاحظة: خانة الإدخال الفعلية أصبحت B4 (مدموجة B4:C4). نُحدّث المرجع:
DATE_CELL = "$B$4"

def reg_lookup(regcol_letter):
    # جلب قيمة اليوم من سجل اليومية عبر INDEX/MATCH على التاريخ
    reg_dates = f"{sref(F_REG)}!$B${R_START}:$B${R_END}"
    reg_col   = f"{sref(F_REG)}!${regcol_letter}${R_START}:${regcol_letter}${R_END}"
    return f'=IFERROR(INDEX({reg_col},MATCH({DATE_CELL},{reg_dates},0)),0)'

# --- صندوق ملخص علوي (باقي سابق / العهدة / المتاح) ---
label_cell("D5", "باقي عهدة سابق", PURPLE); ws_f.merge_cells("D5:E5")
val_cell("B5", reg_lookup("C"), MONEY0, PURPLE); ws_f.merge_cells("B5:C5")
label_cell("D6", "العهدة المستلمة اليوم", GREEN); ws_f.merge_cells("D6:E6")
val_cell("B6", reg_lookup("D"), MONEY0, GREEN); ws_f.merge_cells("B6:C6")
label_cell("D7", "إجمالي العهدة المتاحة", TEAL); ws_f.merge_cells("D7:E7")
val_cell("B7", reg_lookup("E"), MONEY0, TEAL, fill=GOLD_LIGHT, size=13); ws_f.merge_cells("B7:C7")
for rr in (5,6,7): ws_f.row_dimensions[rr].height = 22

# --- جدول رحلات اليوم ---
TR_TITLE = 9
ws_f.merge_cells(f"A{TR_TITLE}:I{TR_TITLE}")
tc = ws_f.cell(TR_TITLE,1,"عُهد ورحلات اليوم")
tc.font = Font(name=FONT_NAME, bold=True, size=12, color=WHITE)
tc.alignment = Alignment(horizontal="center", vertical="center")
fill_block(ws_f, TR_TITLE,1,TR_TITLE,9, NAVY); ws_f.row_dimensions[TR_TITLE].height = 22

TR_HDR = TR_TITLE + 1   # 10
trip_form_heads = ["م","عدد الحاويات","شركة الشحن","اسم الشركة","الجهة","توكيل","الميناء","مالك السيارة","العهد"]
for i,h in enumerate(trip_form_heads, start=1):
    c = ws_f.cell(TR_HDR, i, h)
    c.font = Font(name=FONT_NAME, bold=True, color=WHITE, size=9)
    c.alignment = Alignment(horizontal="center", vertical="center", wrap_text=True)
    c.fill = PatternFill("solid", fgColor=NAVY_MID); c.border = box_thin
ws_f.row_dimensions[TR_HDR].height = 30

TR_ROWS = 30
TR_DATA = TR_HDR + 1     # 11
trip_full = f"{sref(F_TRIP)}!$A$1:$J${T_END}"
# ترتيب أعمدة صفحة العهد: 3=عدد الحاويات,4=شركة الشحن,5=اسم الشركة,6=الجهة,7=توكيل,8=الميناء,9=مالك السيارة,10=العهد
trip_src_idx = [None,None,3,4,5,6,7,8,9,10]  # حسب عمود الفورم 2..9  (العمود1=م تسلسلي)
for k in range(TR_ROWS):
    row = TR_DATA + k
    zebra = (k % 2 == 1)
    # عمود المساعدة K: رقم صف المطابقة رقم (k+1) في صفحة العهد
    helper = f'=IFERROR(SMALL(IF({tdate}={DATE_CELL},ROW({tdate})),{k+1}),"")'
    ws_f.cell(row, 11).value = ArrayFormula(f"K{row}", helper)
    # م (تسلسل داخل اليوم)
    cc = ws_f.cell(row,1); cc.value = f'=IF($K{row}="","",{k+1})'
    style_cell(cc, "formula", INTF, zebra)
    for fcol in range(2,10):
        cell = ws_f.cell(row, fcol)
        idx = trip_src_idx[fcol]
        numf = MONEY if fcol == 9 else (INTF if fcol == 2 else None)
        cell.value = f'=IF($K{row}="","",INDEX({trip_full},$K{row},{idx}))'
        style_cell(cell, "formula", numf, zebra)
# صف مجموع عهد اليوم
TR_SUM = TR_DATA + TR_ROWS
for col in range(1,10):
    c = ws_f.cell(TR_SUM, col); c.fill = PatternFill("solid", fgColor=NAVY)
    c.font = Font(name=FONT_NAME, bold=True, color=GOLD); c.border = Border(top=Side(style="double", color=GOLD))
    c.alignment = Alignment(horizontal="center", vertical="center")
ws_f.cell(TR_SUM,2,"إجمالي عُهد اليوم")
sc = ws_f.cell(TR_SUM,9, f'=SUM($I{TR_DATA}:$I{TR_SUM-1})'); sc.number_format = MONEY

# --- جدول إيصالات اليوم ---
RC_TITLE = TR_SUM + 2
ws_f.merge_cells(f"A{RC_TITLE}:I{RC_TITLE}")
rc = ws_f.cell(RC_TITLE,1,"إيصالات اليوم")
rc.font = Font(name=FONT_NAME, bold=True, size=12, color=WHITE)
rc.alignment = Alignment(horizontal="center", vertical="center")
fill_block(ws_f, RC_TITLE,1,RC_TITLE,9, NAVY); ws_f.row_dimensions[RC_TITLE].height = 22

RC_HDR = RC_TITLE + 1
# رؤوس: م | المبلغ | البيان/الجهة (مدموج)
ws_f.cell(RC_HDR,1,"م"); ws_f.cell(RC_HDR,2,"المبلغ")
ws_f.merge_cells(start_row=RC_HDR, start_column=3, end_row=RC_HDR, end_column=9)
ws_f.cell(RC_HDR,3,"البيان / الجهة")
for i in range(1,10):
    c = ws_f.cell(RC_HDR,i)
    c.font = Font(name=FONT_NAME, bold=True, color=WHITE, size=9)
    c.alignment = Alignment(horizontal="center", vertical="center")
    c.fill = PatternFill("solid", fgColor=NAVY_MID); c.border = box_thin
ws_f.row_dimensions[RC_HDR].height = 22

RC_ROWS = 18
RC_DATA = RC_HDR + 1
rcpt_full = f"{sref(F_RCPT)}!$A$1:$D${T_END}"   # 1=م,2=تاريخ,3=المبلغ,4=البيان
for k in range(RC_ROWS):
    row = RC_DATA + k
    zebra = (k % 2 == 1)
    helper = f'=IFERROR(SMALL(IF({cdate}={DATE_CELL},ROW({cdate})),{k+1}),"")'
    ws_f.cell(row, 12).value = ArrayFormula(f"L{row}", helper)
    cc = ws_f.cell(row,1); cc.value = f'=IF($L{row}="","",{k+1})'
    style_cell(cc, "formula", INTF, zebra)
    amt = ws_f.cell(row,2); amt.value = f'=IF($L{row}="","",INDEX({rcpt_full},$L{row},3))'
    style_cell(amt, "formula", MONEY, zebra)
    ws_f.merge_cells(start_row=row, start_column=3, end_row=row, end_column=9)
    ds = ws_f.cell(row,3); ds.value = f'=IF($L{row}="","",INDEX({rcpt_full},$L{row},4))'
    style_cell(ds, "formula", None, zebra)
    ds.alignment = Alignment(horizontal="right", vertical="center", indent=1)
RC_SUM = RC_DATA + RC_ROWS
for col in range(1,10):
    c = ws_f.cell(RC_SUM, col); c.fill = PatternFill("solid", fgColor=NAVY)
    c.font = Font(name=FONT_NAME, bold=True, color=GOLD); c.border = Border(top=Side(style="double", color=GOLD))
    c.alignment = Alignment(horizontal="center", vertical="center")
ws_f.cell(RC_SUM,3,"إجمالي إيصالات اليوم")
rsum = ws_f.cell(RC_SUM,2, f'=SUM($B{RC_DATA}:$B{RC_SUM-1})'); rsum.number_format = MONEY

# --- صندوق التقفيل النهائي ---
FT = RC_SUM + 2
rows_ft = [
    ("الإجمالي (العهدة المتاحة)", reg_lookup("E"), TEAL, GOLD_LIGHT),
    ("إجمالي العهد (رحلات اليوم)", reg_lookup("F"), RED, WHITE),
    ("إجمالي الإيصالات", reg_lookup("G"), RED, WHITE),
    ("مصروفات أخرى", reg_lookup("H"), RED, WHITE),
    ("المصروف (إجمالي المنصرف)", reg_lookup("I"), RED, RED_LT),
    ("رصيد عهد مُرحَّل لليوم التالي", reg_lookup("J"), GOLD, GOLD_LIGHT),
]
for i,(lab,frm,acc,fl) in enumerate(rows_ft):
    row = FT + i
    ws_f.merge_cells(start_row=row, start_column=4, end_row=row, end_column=6)
    label_cell(f"D{row}", lab, acc, 11)
    ws_f.merge_cells(start_row=row, start_column=2, end_row=row, end_column=3)
    big = (i in (0,4,5))
    val_cell(f"B{row}", frm, MONEY0, acc, fill=fl, size=(14 if big else 11))
    ws_f.row_dimensions[row].height = 24 if big else 20

# توقيع
SG = FT + len(rows_ft) + 1
ws_f.merge_cells(f"D{SG}:E{SG}"); ws_f.cell(SG,4,"توقيع المسؤول:").font = Font(name=FONT_NAME, bold=True, color=INK)
ws_f.cell(SG,4).alignment = Alignment(horizontal="right", vertical="center", indent=1)
ws_f.merge_cells(f"B{SG}:C{SG}")
for cc in ("B","C"): ws_f[f"{cc}{SG}"].border = Border(bottom=Side(style="medium", color=INK))

ws_f.freeze_panes = "A4"
ws_f.print_options.horizontalCentered = True
ws_f.page_setup.orientation = "portrait"
ws_f.page_setup.fitToWidth = 1; ws_f.page_setup.fitToHeight = 0
ws_f.sheet_properties.pageSetUpPr = PageSetupProperties(fitToPage=True)

# ==========================================================================
#                    (6) صفحة الدليل
# ==========================================================================
ws_g = wb.create_sheet(F_GUIDE)
ws_g.sheet_view.rightToLeft = True; ws_g.sheet_view.showGridLines = False
banner(ws_g, 6, "دليل ملف التقفيل اليومي", "اقرأ قبل الاستخدام")
set_widths(ws_g, [3, 30, 46, 3, 3, 3])

def g_title(row, text, color=NAVY):
    ws_g.merge_cells(start_row=row, start_column=2, end_row=row, end_column=3)
    fill_block(ws_g, row, 2, row, 3, color)
    c = ws_g.cell(row, 2, text)
    c.font = Font(name=FONT_NAME, bold=True, size=12, color=WHITE)
    c.alignment = Alignment(horizontal="right", vertical="center", indent=1)
    ws_g.row_dimensions[row].height = 22

def g_line(row, a, b):
    # حماية: أي نص يبدأ بـ = يعتبره Excel معادلة — نضيف مسافة رفيعة بالبداية
    if isinstance(a, str) and a.startswith("="): a = " " + a
    if isinstance(b, str) and b.startswith("="): b = " " + b
    x = ws_g.cell(row, 2, a)
    x.font = Font(name=FONT_NAME, bold=True, color=NAVY, size=10)
    x.alignment = Alignment(horizontal="right", vertical="center", indent=1, wrap_text=True)
    x.border = box_thin; x.fill = PatternFill("solid", fgColor=SECTION_BG)
    y = ws_g.cell(row, 3, b)
    y.font = Font(name=FONT_NAME, color=INK, size=10)
    y.alignment = Alignment(horizontal="right", vertical="center", indent=1, wrap_text=True)
    y.border = box_thin
    ws_g.row_dimensions[row].height = 34

r = 3
g_title(r, "طريقة العمل اليومية"); r += 1
for a,b in [
    ("١) العهد والرحلات", "سجّل كل رحلة في صفحة (العهد والرحلات): التاريخ + عدد الحاويات + شركة الشحن + الشركة + الجهة + التوكيل + الميناء + مالك السيارة + مبلغ العهدة."),
    ("٢) الإيصالات", "سجّل كل إيصال في صفحة (الإيصالات): التاريخ + المبلغ + البيان/الجهة."),
    ("٣) سجل اليومية", "أضِف صفاً واحداً لكل يوم: اكتب (التاريخ) و(العهدة المستلمة) و(مصروفات أخرى إن وجدت). الباقي يُحسب تلقائياً ويترحّل الرصيد لليوم التالي."),
    ("٤) تقفيل اليوم", "افتح صفحة (تقفيل اليوم)، اكتب التاريخ في الخانة الصفراء، فيظهر تقفيل اليوم كامل جاهز للطباعة."),
]:
    g_line(r, a, b); r += 1

r += 1
g_title(r, "معادلة التقفيل", GOLD); r += 1
for a,b in [
    ("باقي عهدة سابق", "رصيد أمس المُرحَّل (وأول يوم = الرصيد الافتتاحي في صفحة الإعدادات)."),
    ("إجمالي العهدة المتاحة", "باقي عهدة سابق + العهدة المستلمة اليوم."),
    ("إجمالي المصروف", "عهد الرحلات + الإيصالات + مصروفات أخرى."),
    ("رصيد مُرحَّل لليوم التالي", "إجمالي العهدة المتاحة ناقص إجمالي المصروف (لو ظهر أحمر يبقى فيه عجز عهدة)."),
]:
    g_line(r, a, b); r += 1

r += 1
g_title(r, "القوائم والربط بالأساسي", RED); r += 1
for a,b in [
    ("قوائم من الأساسي", "الشركات + الجهات + ملاك السيارات نسخة محدَّثة من الملف الأساسي، مدمجة داخل هذا الملف — تظهر فوراً بدون أي روابط."),
    ("تحديث القوائم", "لما تضيف أسماء جديدة في الأساسي، شغّل سكربت المزامنة (sync_lists.py) فيحدّث القوائم هنا بدون ما يلمس بياناتك."),
    ("قوائم محلية", "شركة الشحن + التوكيل + الميناء قوائم خاصة بهذا الملف — عدّلها مباشرة في صفحة (الإعدادات)."),
    ("الكتابة اليدوية", "القوائم للتسهيل فقط ولا تمنع الكتابة — تقدر تكتب أي اسم مش موجود في القائمة عادي."),
    ("ملاحظة", "ما فيش أي ارتباط بملف خارجي، فالملف يفتح لوحده من غير رسائل تحديث روابط."),
]:
    g_line(r, a, b); r += 1
ws_g.freeze_panes = "A3"

# ==========================================================================
#                    ترتيب الصفحات + الألوان + الحفظ
# ==========================================================================
order = [F_FORM, F_REG, F_TRIP, F_RCPT, F_SET, F_GUIDE]
wb._sheets.sort(key=lambda s: order.index(s.title) if s.title in order else 99)
wb.active = wb.sheetnames.index(F_FORM)
tabcolors = {F_FORM:NAVY, F_REG:GOLD, F_TRIP:GREEN, F_RCPT:TEAL, F_SET:"FF666666", F_GUIDE:"FF999999"}
for name,col in tabcolors.items():
    wb[name].sheet_properties.tabColor = col[2:]

wb.save(OUT)
print("تم حفظ الملف:", OUT)
print("عدد أسماء القوائم المدمجة من الأساسي:",
      sum(len(cache_vals[c]) for c,_ in SHARED),
      "(شركات %d، جهات %d، ملاك %d)" % (len(cache_vals['E']), len(cache_vals['F']), len(cache_vals['H'])))
