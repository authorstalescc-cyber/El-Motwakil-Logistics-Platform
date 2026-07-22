#!/usr/bin/env python3
"""فحص 08-C: رحلة فاتورة/تحصيل مترابطة ورحلة تقفيل كاملة."""

import json
import os
import re
import subprocess
from pathlib import Path

from playwright.sync_api import sync_playwright


root = Path(__file__).resolve().parents[1]
base_url = os.environ.get("ALMOTAWAKEL_E2E_URL", "http://127.0.0.1:8795")
db_name = os.environ.get("ALMOTAWAKEL_TEST_DB", "")
if not re.fullmatch(r"almotawakel_test_[a-z0-9_]{6,48}", db_name):
    raise SystemExit("يرفض فحص الرحلات العمل بلا ALMOTAWAKEL_TEST_DB معزولة")


def php(script: str) -> str:
    return subprocess.check_output(["php", "-r", script], cwd=root, text=True).strip()


fixture = json.loads(
    php(
        "require 'app/bootstrap.php';$suffix=substr(bin2hex(random_bytes(6)),0,12);"
        "$client='__ux08c عميل '.$suffix;$permitNo='__ux08c_'.$suffix;"
        "\\App\\Core\\Database::exec(\"INSERT INTO permits"
        "(permit_type,permit_no,permit_date,company,client,containers,status) "
        "VALUES('sader',?,CURRENT_DATE,'شركة رحلة الاختبار',?,1,'open')\",[$permitNo,$client]);"
        "$permitId=\\App\\Core\\Database::lastId();\\App\\Models\\Permit::createMovement($permitId);"
        "$invoiceId=\\App\\Models\\PermitInvoice::create(["
        "'permit_id'=>$permitId,'doc_kind'=>'invoice','taxable'=>0,'invoice_date'=>date('Y-m-d'),"
        "'client'=>$client,'display_client'=>$client,'company'=>'شركة رحلة الاختبار',"
        "'destination'=>'وجهة رحلة الاختبار','agency'=>'توكيل رحلة الاختبار','port'=>'ميناء الاختبار',"
        "'permit_no'=>$permitNo,'permit_type'=>'sader','containers'=>1,'items'=>["
        "['label'=>'نولون رحلة الاختبار','qty'=>1,'price'=>1250,'freight'=>true]]]);"
        "$c=require 'config/config.php';$owner=$c['default_user'];"
        "echo json_encode(['client'=>$client,'permit_id'=>$permitId,'invoice_id'=>$invoiceId,"
        "'owner_user'=>$owner['username'],'owner_pass'=>$owner['password'],"
        "'partial_marker'=>'__ux08c_partial_'.$suffix,'cash_marker'=>'__ux08c_cash_'.$suffix,"
        "'blocked_marker'=>'__ux08c_blocked_'.$suffix],JSON_UNESCAPED_UNICODE);"
    )
)

test_date = "2026-07-20"
failure_shot = "/tmp/ux08c-workflows-failure.png"
console_errors: list[str] = []
response_errors: list[str] = []


def watch(page) -> None:
    page.on("console", lambda message: console_errors.append(message.text) if message.type == "error" else None)
    page.on(
        "response",
        lambda response: response_errors.append(f"{response.status} {response.url}")
        if response.url.startswith(base_url) and response.status >= 400 else None,
    )


def login(page) -> None:
    page.goto(base_url + "/login", wait_until="networkidle")
    page.locator('input[name="username"]').fill(fixture["owner_user"])
    page.locator('input[name="password"]').fill(fixture["owner_pass"])
    page.locator('button[type="submit"]').click()
    page.wait_for_load_state("networkidle")
    if "/work-date" in page.url:
        page.locator('input[name="work_date"]').fill(test_date)
        page.locator('button[type="submit"]').first.click()
        page.wait_for_load_state("networkidle")


def fill_payment(page, amount: str, note: str, route: str, auto_allocate: bool) -> None:
    form = page.locator("#cpForm")
    form.locator('select[name="client_name"]').select_option(label=fixture["client"])
    form.locator('input[name="pay_date"]').fill(test_date)
    form.locator('input[name="amount"]').fill(amount)
    form.locator('input[name="note"]').fill(note)
    form.locator(f'input[name="route"][value="{route}"]').check()
    checkbox = form.locator('input[name="auto_allocate"]')
    checkbox.set_checked(auto_allocate)


def submit_payment(page) -> None:
    with page.expect_navigation(wait_until="networkidle"):
        page.locator('#cpForm [data-primary-submit]').click()


def confirm_current_form(page) -> None:
    page.locator(".confirm-overlay.open").wait_for(state="visible")
    with page.expect_navigation(wait_until="networkidle"):
        page.locator(".confirm-overlay.open .confirm-ok").click()


try:
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        context = browser.new_context(locale="ar-EG", viewport={"width": 1440, "height": 900})
        page = context.new_page()
        watch(page)
        try:
            login(page)

            # 1) تحصيل جزئي يخصص تلقائيًا على الفاتورة الأقدم.
            page.goto(base_url + "/payments", wait_until="networkidle")
            fill_payment(page, "400", fixture["partial_marker"], "none", True)
            submit_payment(page)
            row = page.locator("tbody tr", has_text=fixture["partial_marker"])
            assert row.count() == 1 and "مرحّل" in row.inner_text(), "التحصيل الجزئي لم يظهر في السجل"
            invoice = json.loads(
                php(
                    "require 'app/bootstrap.php';echo json_encode(\\App\\Core\\Database::row("
                    f"\"SELECT status,paid_amount,balance_due FROM permit_invoices WHERE id={int(fixture['invoice_id'])}\"));"
                )
            )
            assert invoice["status"] == "partially_paid"
            assert abs(float(invoice["paid_amount"]) - 400) < 0.005
            assert abs(float(invoice["balance_due"]) - 850) < 0.005

            # 2) العكس من نفس الشاشة يعيد رصيد الفاتورة ويحفظ سجل التحصيل.
            row.locator('input[name="reason"]').fill("اختبار عكس مترابط")
            row.get_by_title("عكس التحصيل").click()
            confirm_current_form(page)
            reversed_row = page.locator("tbody tr", has_text=fixture["partial_marker"])
            assert reversed_row.count() == 1 and "معكوس" in reversed_row.inner_text()
            invoice = json.loads(
                php(
                    "require 'app/bootstrap.php';echo json_encode(\\App\\Core\\Database::row("
                    f"\"SELECT status,paid_amount,balance_due FROM permit_invoices WHERE id={int(fixture['invoice_id'])}\"));"
                )
            )
            assert invoice["status"] == "issued"
            assert abs(float(invoice["paid_amount"])) < 0.005
            assert abs(float(invoice["balance_due"]) - 1250) < 0.005

            # 3) تحصيل نقدي غير مخصص يدخل عهدة اليوم، ثم مطابقة وإقفال.
            fill_payment(page, "600", fixture["cash_marker"], "cash", False)
            submit_payment(page)
            cash_count = int(
                php(
                    "require 'app/bootstrap.php';echo (int)\\App\\Core\\Database::scalar("
                    f"\"SELECT COUNT(*) FROM client_payments WHERE note='{fixture['cash_marker']}' "
                    "AND route='cash' AND status='posted' AND custody_id IS NOT NULL\");"
                )
            )
            assert cash_count == 1, "التحصيل النقدي لم ينشئ عهدة اليوم مرة واحدة"

            page.goto(base_url + f"/closing?date={test_date}", wait_until="networkidle")
            assert "● يوم مفتوح" in page.locator(".cl-state").inner_text()
            for recon_row in page.locator(".cl-client-card.recon-row").all():
                recon_row.locator(".manual-total").fill(recon_row.get_attribute("data-system-total") or "0")
            general_card = page.locator(".cl-general-card")
            page.locator('input[name="manual_general_expenses"]').fill(
                general_card.get_attribute("data-system-total") or "0"
            )
            page.locator('textarea[name="reconciliation_note"]').fill("مطابقة رحلة UX08C")
            with page.expect_navigation(wait_until="networkidle"):
                page.locator(".cl-save-recon").click()
            assert "مطابقة كاملة" in page.locator(".cl-recon-status").inner_text()
            assert page.locator(".cl-close-button").is_visible(), "زر الإقفال لم يظهر بعد المطابقة"

            page.locator(".cl-close-button").click()
            confirm_current_form(page)
            assert "يوم متقفل" in page.locator(".cl-state").inner_text()
            assert int(
                php(
                    "require 'app/bootstrap.php';echo (int)\\App\\Core\\Database::scalar("
                    f"\"SELECT COUNT(*) FROM daily_closings WHERE close_date='{test_date}'\");"
                )
            ) == 1

            # 4) الكتابة النقدية على يوم متقفل تُرفض مع حفظ كل قيم المستخدم.
            page.goto(base_url + "/payments", wait_until="networkidle")
            fill_payment(page, "75", fixture["blocked_marker"], "cash", False)
            submit_payment(page)
            form = page.locator("#cpForm")
            assert page.locator("[data-form-error-summary]").count() == 1
            assert form.locator('input[name="pay_date"]').get_attribute("aria-invalid") == "true"
            assert form.locator('input[name="amount"]').input_value() == "75"
            assert form.locator('input[name="note"]').input_value() == fixture["blocked_marker"]
            assert form.locator('input[name="route"][value="cash"]').is_checked()
            assert not form.locator('input[name="auto_allocate"]').is_checked(), (
                "خيار التخصيص الذي ألغاه المستخدم عاد مفعّلًا بعد خطأ الإقفال"
            )
            assert int(
                php(
                    "require 'app/bootstrap.php';echo (int)\\App\\Core\\Database::scalar("
                    f"\"SELECT COUNT(*) FROM client_payments WHERE note='{fixture['blocked_marker']}'\");"
                )
            ) == 0

            # 5) فتح الإقفال من تبويب ثانٍ، ثم نجاح نفس النموذج والرمز بعد عودة الصلاحية.
            closing_page = context.new_page()
            watch(closing_page)
            closing_page.goto(base_url + f"/closing?date={test_date}", wait_until="networkidle")
            closing_page.get_by_role("button", name=re.compile("فتح الإقفال")).click()
            confirm_current_form(closing_page)
            assert "يوم مفتوح" in closing_page.locator(".cl-state").inner_text()
            closing_page.close()

            submit_payment(page)
            success_row = page.locator("tbody tr", has_text=fixture["blocked_marker"])
            assert success_row.count() == 1 and "مرحّل" in success_row.inner_text()
            assert int(
                php(
                    "require 'app/bootstrap.php';echo (int)\\App\\Core\\Database::scalar("
                    f"\"SELECT COUNT(*) FROM client_payments WHERE note='{fixture['blocked_marker']}' "
                    "AND status='posted'\");"
                )
            ) == 1
        except Exception:
            page.screenshot(path=failure_shot, full_page=True)
            raise
        finally:
            browser.close()
finally:
    pass

assert not response_errors, f"استجابات HTTP فاشلة: {response_errors}"
assert not console_errors, f"أخطاء console: {console_errors}"
print("UX08-C workflows: partial payment/reversal + reconcile/close/guard/reopen/retry passed")
