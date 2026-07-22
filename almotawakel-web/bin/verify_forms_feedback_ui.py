#!/usr/bin/env python3
"""اختبار متصفح للخطة 04 على قاعدة معزولة فقط."""
import json
import os
import secrets
import subprocess
from pathlib import Path

from playwright.sync_api import sync_playwright


root = Path(__file__).resolve().parents[1]
base_url = os.environ.get("ALMOTAWAKEL_E2E_URL", "http://127.0.0.1:8792")
db_name = os.environ.get("ALMOTAWAKEL_TEST_DB", "")
if not db_name.startswith("almotawakel_test_"):
    raise SystemExit("يرفض اختبار الواجهة العمل بلا ALMOTAWAKEL_TEST_DB معزولة")

php = (
    "$c=require 'config/config.php';"
    "echo json_encode([$c['default_user']['username'],$c['default_user']['password']]);"
)
username, password = json.loads(subprocess.check_output(["php", "-r", php], cwd=root, text=True))

console_errors = []
response_errors = []
with sync_playwright() as playwright:
    browser = playwright.chromium.launch(headless=True)
    context = browser.new_context(locale="ar-EG")
    page = context.new_page()
    page.on("console", lambda msg: console_errors.append(msg.text) if msg.type == "error" else None)
    page.on("response", lambda res: response_errors.append(f"{res.status} {res.url}") if res.status >= 400 else None)

    page.goto(base_url + "/login", wait_until="networkidle")
    page.locator('input[name="username"]').fill(username)
    page.locator('input[name="password"]').fill(password)
    page.locator('button[type="submit"]').click()
    page.wait_for_load_state("networkidle")
    if "/work-date" in page.url:
        page.locator('input[name="work_date"]').fill("2026-07-20")
        page.locator('button[type="submit"]').first.click()
        page.wait_for_load_state("networkidle")

    # تبويبان لنفس النموذج: كل واحد يحتفظ بقيمه وخطئه ولا يسرّبها للآخر.
    first = page
    second = context.new_page()
    second.on("console", lambda msg: console_errors.append(msg.text) if msg.type == "error" else None)
    second.on("response", lambda res: response_errors.append(f"{res.status} {res.url}") if res.status >= 400 else None)
    for tab, category in ((first, "اختبار تبويب أول"), (second, "اختبار تبويب ثان")):
        tab.goto(base_url + "/expenses/new?back=" + ("expenses%3Fq%3Dاختبار%26page%3D2%23row-7"), wait_until="networkidle")
        tab.locator('input[name="category"]').fill(category)
        tab.locator('input[name="amount"]').fill("0")
        tab.locator("#expForm").evaluate("form => form.noValidate = true")
        tab.locator('[data-primary-submit]').click()
        tab.wait_for_load_state("networkidle")
        assert "_form=" in tab.url, "فشل PRG لم يحمل رمز الحقيبة"
        assert tab.locator('[data-form-error-summary]').count() == 1
        assert tab.locator('input[name="amount"]').get_attribute("aria-invalid") == "true"
        assert tab.locator('input[name="category"]').input_value() == category
        assert tab.locator('input[name="_back"]').input_value() == "expenses?q=اختبار&page=2#row-7"
    assert first.locator('input[name="category"]').input_value() == "اختبار تبويب أول"
    assert second.locator('input[name="category"]').input_value() == "اختبار تبويب ثان"

    # رابط رجوع خارجي يُرفض ولا يدخل في الحقل المخفي.
    first.goto(base_url + "/expenses/new?back=https%3A%2F%2Fevil.example%2Fsteal", wait_until="networkidle")
    assert first.locator('input[name="_back"]').input_value() == "expenses"

    # كتابة مالية ناجحة ثم نقر سريع ثانٍ: صف واحد فقط وزر بحالة busy.
    marker = "__ux04_double_submit_" + secrets.token_hex(6) + "__"
    first.locator('input[name="category"]').fill("اختبار UX")
    first.locator('input[name="beneficiary"]').fill("اختبار")
    first.locator('input[name="amount"]').fill("12.34")
    first.locator('input[name="note"]').fill(marker)
    submit = first.locator('[data-primary-submit]')
    with first.expect_navigation(wait_until="networkidle"):
        submit.evaluate("button => { button.click(); button.click(); }")
    first.goto(base_url + "/expenses?q=" + marker, wait_until="networkidle")
    rows = first.locator("tbody tr", has_text=marker)
    assert rows.count() == 1, f"الإرسال المزدوج أنشأ {rows.count()} صفوف"

    # تحصيل غير صالح: أخطاء حقول وقيم محفوظة بدل رسالة عامة وفقد الإدخال.
    first.goto(base_url + "/payments", wait_until="networkidle")
    first.locator('#cpForm input[name="amount"]').fill("0")
    first.locator('#cpForm input[name="note"]').fill("قيمة يجب ألا تضيع")
    first.locator("#cpForm").evaluate("form => form.noValidate = true")
    first.locator('#cpForm [data-primary-submit]').click()
    first.wait_for_load_state("networkidle")
    assert first.locator('[data-form-error-summary]').count() == 1
    assert first.locator('#cpForm input[name="note"]').input_value() == "قيمة يجب ألا تضيع"
    assert first.locator('#cpForm input[name="amount"]').get_attribute("aria-invalid") == "true"

    # إدارة الإذن تستخدم نفس العقد المركزي.
    first.goto(base_url + "/permits/new", wait_until="networkidle")
    assert first.locator('input[name="_form_token"]').count() == 1
    assert first.locator('[data-primary-submit]').count() == 1

    # سجل الفواتير يرسل رمز الإلغاء حتى لو كانت القاعدة الجديدة بلا مستندات.
    first.goto(base_url + "/invoices", wait_until="networkidle")
    assert first.locator('#bulkForm input[name="_form_token"]').count() == 1

    # موجة عهدة المندوب الجديدة تُرندر بأمان على قاعدة فارغة، والصندوق يفتح بلا أخطاء أصول/console.
    first.goto(base_url + "/rep-custody", wait_until="networkidle")
    assert first.get_by_text("عهدة المناديب", exact=True).count() >= 1
    first.goto(base_url + "/rep-custody/inbox", wait_until="networkidle")
    assert first.get_by_text("مراجعة بنود المناديب", exact=True).count() >= 1

    assert not response_errors, f"استجابات HTTP فاشلة: {response_errors}"
    assert not console_errors, f"أخطاء console: {console_errors}"
    browser.close()

print("UX04 UI: isolated tabs, field errors, safe back and one financial submit verified")
