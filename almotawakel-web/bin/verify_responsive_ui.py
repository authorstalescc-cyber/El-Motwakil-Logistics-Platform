#!/usr/bin/env python3
"""فحص 07-B للتجاوب وأهداف اللمس على قاعدة معزولة فقط."""

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
    raise SystemExit("يرفض فحص التجاوب العمل بلا ALMOTAWAKEL_TEST_DB معزولة")


def php(script: str) -> str:
    return subprocess.check_output(["php", "-r", script], cwd=root, text=True).strip()


username, password = json.loads(php(
    "$c=require 'config/config.php';"
    "echo json_encode([$c['default_user']['username'],$c['default_user']['password']]);"
))

permit_id = int(php(
    "require 'app/bootstrap.php';"
    "\\App\\Core\\Database::exec(\"DELETE FROM permits WHERE permit_no='__ux07_responsive__'\");"
    "\\App\\Core\\Database::exec(\"INSERT INTO permits"
    "(permit_type,permit_no,permit_date,company,client,agency,port,containers,status) "
    "VALUES('sader','__ux07_responsive__',CURRENT_DATE,'شركة اختبار','عميل اختبار','توكيل اختبار','ميناء اختبار',1,'open')\");"
    "$id=\\App\\Core\\Database::lastId();\\App\\Models\\Permit::createMovement($id);echo $id;"
))

console_errors: list[str] = []
response_errors: list[str] = []
failure_shot = "/tmp/ux07-responsive-failure.png"
capture_dir = os.environ.get("UX07_CAPTURE_DIR", "")
viewports = [
    (320, 800, "320px"),
    (720, 900, "zoom-200-equivalent"),
    (768, 900, "768px"),
    (1440, 900, "1440px"),
]


def assert_no_document_overflow(page, label: str) -> None:
    widths = page.evaluate("""() => ({
      scroll: document.documentElement.scrollWidth,
      client: document.documentElement.clientWidth,
      offenders: Array.from(document.querySelectorAll('body *')).map(element => {
        const rect = element.getBoundingClientRect();
        return {tag: element.tagName, id: element.id, cls: String(element.className || '').slice(0, 80), left: rect.left, right: rect.right};
      }).filter(item => item.left < -1 || item.right > document.documentElement.clientWidth + 1).slice(0, 8)
    })""")
    assert widths["scroll"] <= widths["client"] + 1, f"تمدد أفقي في {label}: {widths}"


def assert_inside_viewport(page, selector: str, label: str, viewport_width: int) -> None:
    locator = page.locator(selector).first
    assert locator.is_visible(), f"الإجراء {label} غير ظاهر"
    box = locator.bounding_box()
    assert box, f"تعذر قياس {label}"
    assert box["x"] >= -1 and box["x"] + box["width"] <= viewport_width + 1, f"{label} مقصوص أفقيًا: {box}"


def assert_touch_height(page, selector: str, label: str) -> None:
    box = page.locator(selector).first.bounding_box()
    assert box and box["height"] >= 44, f"هدف اللمس {label} ارتفاعه {0 if not box else box['height']:.1f}px"


def login(page) -> None:
    page.locator("#login-username").fill(username)
    page.locator("#login-password").fill(password)
    page.locator('button[type="submit"]').click()
    page.wait_for_load_state("networkidle")
    if "/work-date" in page.url:
        page.locator('input[name="work_date"]').fill("2026-07-20")
        page.locator('button[type="submit"]').first.click()
        page.wait_for_load_state("networkidle")


try:
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        try:
            for width, height, viewport_label in viewports:
                context = browser.new_context(locale="ar-EG", viewport={"width": width, "height": height})
                page = context.new_page()
                page.on("console", lambda msg: console_errors.append(msg.text) if msg.type == "error" else None)
                page.on("response", lambda res: response_errors.append(f"{res.status} {res.url}") if res.status >= 400 else None)

                try:
                    page.goto(base_url + "/login", wait_until="networkidle")
                    assert_no_document_overflow(page, f"الدخول {viewport_label}")
                    assert_inside_viewport(page, '.login-body button[type="submit"]', f"زر الدخول {viewport_label}", width)
                    if capture_dir and width == 320:
                        Path(capture_dir).mkdir(parents=True, exist_ok=True)
                        page.screenshot(path=str(Path(capture_dir) / "login-320.png"), full_page=True)
                    if width <= 768:
                        assert_touch_height(page, "#login-username", f"اسم المستخدم {viewport_label}")
                        assert_touch_height(page, "#login-password", f"كلمة السر {viewport_label}")
                        assert_touch_height(page, '.login-body button[type="submit"]', f"زر الدخول {viewport_label}")
                    login(page)

                    page.goto(base_url + "/expenses/new", wait_until="networkidle")
                    assert_no_document_overflow(page, f"المصروف {viewport_label}")
                    assert_inside_viewport(page, "#expSubmit", f"حفظ المصروف {viewport_label}", width)
                    if capture_dir and width == 320:
                        page.screenshot(path=str(Path(capture_dir) / "expenses-320.png"), full_page=True)
                    if width <= 768:
                        page.locator(".menu-btn").click()
                        assert page.locator("#primary-sidebar").is_visible()
                        assert page.locator(".menu-btn").get_attribute("aria-expanded") == "true"
                        page.mouse.click(10, 300)
                        assert not page.locator("#primary-sidebar").is_visible()
                        assert page.locator(".menu-btn").get_attribute("aria-expanded") == "false"
                        assert_touch_height(page, ".menu-btn", f"زر القائمة {viewport_label}")
                        assert_touch_height(page, "#expense-date", f"تاريخ المصروف {viewport_label}")
                        assert_touch_height(page, "#expense-amount", f"مبلغ المصروف {viewport_label}")
                        assert_touch_height(page, "#expSubmit", f"حفظ المصروف {viewport_label}")

                    page.goto(base_url + f"/permits/{permit_id}/invoice", wait_until="networkidle")
                    for name in (
                        "اسم العميل في الطباعة (اختياري)", "عنوان الفاتورة (اختياري)",
                        "رقم مسلسل خاص بالعميل (اختياري)", "ملاحظة على الفاتورة (اختياري)",
                        "تاريخ الإصدار", "تاريخ الاستحقاق",
                    ):
                        assert page.get_by_label(name, exact=True).count() == 1, f"حقل الفاتورة {name} بلا تسمية"
                    assert page.locator('input[aria-label^="بيان البند"]').count() >= 1
                    assert page.locator('input[aria-label^="كمية البند"]').count() >= 1
                    assert page.locator('input[aria-label^="سعر البند"]').count() >= 1
                    assert page.locator(".permit-bar .pill").inner_text().strip(), "حالة الإذن تعتمد على اللون وحده"
                    assert_no_document_overflow(page, f"الفاتورة {viewport_label}")
                    assert_inside_viewport(page, '#invForm [data-primary-submit]', f"إصدار الفاتورة {viewport_label}", width)
                    if capture_dir and width == 320:
                        page.screenshot(path=str(Path(capture_dir) / "invoice-320.png"), full_page=True)
                    if width <= 768:
                        assert_touch_height(page, "#invoice-display-client", f"عميل الطباعة {viewport_label}")
                        assert_touch_height(page, "#invoice-date", f"تاريخ الفاتورة {viewport_label}")
                        assert_touch_height(page, '#invForm [data-primary-submit]', f"إصدار الفاتورة {viewport_label}")
                    if width == 1440:
                        page.get_by_label("اسم العميل في الطباعة (اختياري)", exact=True).focus()
                        page.keyboard.press("Tab")
                        assert page.locator(":focus").get_attribute("id") == "invoice-custom-title"
                        page.get_by_role("button", name="إضافة بند").click()
                        assert page.get_by_label("بيان البند الجديد", exact=True).count() == 1
                        assert page.get_by_label("كمية البند الجديد", exact=True).count() == 1
                        assert page.get_by_label("سعر البند الجديد", exact=True).count() == 1
                except Exception:
                    page.screenshot(path=failure_shot, full_page=True)
                    raise
                finally:
                    context.close()
        finally:
            browser.close()
finally:
    php(
        "require 'app/bootstrap.php';"
        f"\\App\\Core\\Database::exec('DELETE FROM permit_movements WHERE permit_id=?',[{permit_id}]);"
        f"\\App\\Core\\Database::exec('DELETE FROM permits WHERE id=?',[{permit_id}]);"
    )

assert not response_errors, f"استجابات HTTP فاشلة: {response_errors}"
assert not console_errors, f"أخطاء console: {console_errors}"
print("UX07 responsive: login, expenses and invoice passed at 320/720/768/1440 with 44px touch targets")
