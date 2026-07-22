#!/usr/bin/env python3
"""فحص متصفح 07-A للدخول ونموذج المصروف، على قاعدة معزولة فقط."""

import json
import os
import re
import subprocess
from pathlib import Path

from playwright.sync_api import sync_playwright


root = Path(__file__).resolve().parents[1]
base_url = os.environ.get("ALMOTAWAKEL_E2E_URL", "http://127.0.0.1:8794")
db_name = os.environ.get("ALMOTAWAKEL_TEST_DB", "")
if not re.fullmatch(r"almotawakel_test_[a-z0-9_]{6,48}", db_name):
    raise SystemExit("يرفض فحص الوصولية العمل بلا ALMOTAWAKEL_TEST_DB معزولة")


def php(script: str) -> str:
    return subprocess.check_output(["php", "-r", script], cwd=root, text=True).strip()


def parse_rgb(value: str) -> tuple[int, int, int]:
    parts = [int(float(item)) for item in re.findall(r"[\d.]+", value)[:3]]
    if len(parts) != 3:
        raise AssertionError(f"لون غير قابل للقياس: {value}")
    return tuple(parts)


def luminance(rgb: tuple[int, int, int]) -> float:
    channels = []
    for value in rgb:
        channel = value / 255
        channels.append(channel / 12.92 if channel <= 0.04045 else ((channel + 0.055) / 1.055) ** 2.4)
    return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2]


def contrast(foreground: str, background: str) -> float:
    first, second = sorted((luminance(parse_rgb(foreground)), luminance(parse_rgb(background))), reverse=True)
    return (first + 0.05) / (second + 0.05)


contrast_results: list[tuple[str, float, float]] = []


def assert_contrast(page, selector: str, label: str) -> None:
    sample = page.locator(selector).first.evaluate(
        """element => {
          const style = getComputedStyle(element);
          let node = element;
          let background = 'rgb(255, 255, 255)';
          while (node) {
            const value = getComputedStyle(node).backgroundColor;
            const match = value.match(/rgba?\\(([^)]+)\\)/);
            const alpha = match && match[1].split(',').length > 3
              ? Number(match[1].split(',')[3]) : 1;
            if (match && alpha > 0.95) { background = value; break; }
            node = node.parentElement;
          }
          return {
            foreground: style.color,
            background,
            size: parseFloat(style.fontSize),
            weight: Number(style.fontWeight) || 400
          };
        }"""
    )
    ratio = contrast(sample["foreground"], sample["background"])
    large = sample["size"] >= 24 or (sample["size"] >= 18.66 and sample["weight"] >= 700)
    minimum = 3.0 if large else 4.5
    assert ratio >= minimum, f"تباين {label} = {ratio:.2f}:1، والمطلوب {minimum}:1"
    contrast_results.append((label, ratio, minimum))


credentials = php(
    "$c=require 'config/config.php';"
    "echo json_encode([$c['default_user']['username'],$c['default_user']['password']]);"
)
username, password = json.loads(credentials)

fixture = "__ux07_accessibility__"
php(
    "require 'app/bootstrap.php';"
    "\\App\\Core\\Database::exec(\"DELETE FROM reps WHERE name='__ux07_accessibility__'\");"
    "\\App\\Core\\Database::exec(\"INSERT INTO reps(name,status) VALUES('__ux07_accessibility__','active')\");"
)

console_errors: list[str] = []
response_errors: list[str] = []
failure_shot = "/tmp/ux07-accessibility-failure.png"
capture_dir = os.environ.get("UX07_CAPTURE_DIR", "")

try:
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        context = browser.new_context(locale="ar-EG", viewport={"width": 1440, "height": 900})
        page = context.new_page()
        page.on("console", lambda msg: console_errors.append(msg.text) if msg.type == "error" else None)
        page.on("response", lambda res: response_errors.append(f"{res.status} {res.url}") if res.status >= 400 else None)

        try:
            page.goto(base_url + "/login", wait_until="networkidle")
            assert page.locator("#login-username").count() == 1
            assert page.locator(":focus").get_attribute("id") == "login-username"
            page.keyboard.press("Tab")
            assert page.locator(":focus").get_attribute("id") == "login-password"
            page.keyboard.press("Tab")
            assert page.locator(":focus").get_attribute("name") == "free_edit_mode"
            page.keyboard.press("Tab")
            assert page.locator(":focus").get_attribute("type") == "submit"

            for selector, label in (
                (".login-body .field label", "تسمية حقل الدخول"),
                ("#login-username", "نص حقل الدخول"),
                (".login-body .btn.gold", "زر الدخول"),
                (".free-login-option small", "شرح الدخول الحر"),
            ):
                assert_contrast(page, selector, label)
            assert page.evaluate("document.documentElement.scrollWidth <= document.documentElement.clientWidth")
            if capture_dir:
                Path(capture_dir).mkdir(parents=True, exist_ok=True)
                page.screenshot(path=str(Path(capture_dir) / "login.png"), full_page=True)

            page.locator("#login-username").fill(username)
            page.locator("#login-password").fill(password)
            page.locator('button[type="submit"]').click()
            page.wait_for_load_state("networkidle")
            if "/work-date" in page.url:
                page.locator('input[name="work_date"]').fill("2026-07-20")
                page.locator('button[type="submit"]').first.click()
                page.wait_for_load_state("networkidle")

            page.goto(base_url + "/expenses/new", wait_until="networkidle")
            for name in ("التاريخ", "الفئة", "الجهة / المستفيد", "المبلغ", "البيان / ملاحظة"):
                assert page.get_by_label(name, exact=True).count() == 1, f"الحقل {name} بلا تسمية صريحة"

            page.evaluate("document.activeElement && document.activeElement.blur()")
            page.keyboard.press("Tab")
            assert "skip-link" in (page.locator(":focus").get_attribute("class") or "")
            page.keyboard.press("Enter")
            assert page.locator(":focus").get_attribute("id") == "main-content"

            page.locator('label[for="expense-amount"]').click()
            assert page.locator(":focus").get_attribute("id") == "expense-amount"
            page.get_by_label("المندوب", exact=True).select_option(label=fixture)
            assert not page.locator("#repKindWrap").is_hidden()
            assert not page.locator("#repHint").is_hidden()
            assert page.locator("#expSubmit").inner_text() == "💾 حفظ في حساب المندوب"
            page.get_by_label("المندوب", exact=True).select_option("")
            assert page.locator("#repKindWrap").is_hidden()
            assert page.locator("#repHint").is_hidden()
            assert page.locator("#expSubmit").inner_text() == "💾 حفظ المصروف"

            for selector, label in (
                (".form-grid .field label", "تسمية حقل المصروف"),
                ("#expense-amount", "نص مبلغ المصروف"),
                ("#expSubmit", "زر حفظ المصروف"),
                (".expense-rep-hint", "شرح تخصيص المندوب"),
            ):
                if selector == ".expense-rep-hint":
                    page.get_by_label("المندوب", exact=True).select_option(label=fixture)
                assert_contrast(page, selector, label)
            assert page.evaluate("document.documentElement.scrollWidth <= document.documentElement.clientWidth")
            if capture_dir:
                page.screenshot(path=str(Path(capture_dir) / "expenses.png"), full_page=True)
            assert not response_errors, f"استجابات HTTP فاشلة: {response_errors}"
            assert not console_errors, f"أخطاء console: {console_errors}"
        except Exception:
            page.screenshot(path=failure_shot, full_page=True)
            raise
        finally:
            browser.close()
finally:
    php(
        "require 'app/bootstrap.php';"
        "\\App\\Core\\Database::exec(\"DELETE FROM reps WHERE name='__ux07_accessibility__'\");"
    )

minimum_measured = min(ratio for _, ratio, _ in contrast_results)
print(
    f"UX07 UI: login + expenses labels, keyboard, behavior and "
    f"{len(contrast_results)} contrast samples verified (minimum {minimum_measured:.2f}:1)"
)
