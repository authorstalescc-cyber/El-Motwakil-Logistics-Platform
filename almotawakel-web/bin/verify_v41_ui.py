#!/usr/bin/env python3
"""فحص متصفح صغير لتسمية الرصيد السالب؛ يتطلب قاعدة اختبار وحسابًا مؤقتًا."""
import os
from playwright.sync_api import sync_playwright


base_url = os.environ.get("ALMOTAWAKEL_E2E_URL", "http://127.0.0.1:8791")
username = os.environ.get("ALMOTAWAKEL_E2E_USER", "")
password = os.environ.get("ALMOTAWAKEL_E2E_PASS", "")
fixture = os.environ.get("ALMOTAWAKEL_E2E_FIXTURE", "")
if not username or not password or not fixture:
    raise SystemExit("متغيرات حساب E2E واسم fixture مطلوبة")

console_errors = []
response_errors = []
with sync_playwright() as playwright:
    browser = playwright.chromium.launch(headless=True)
    page = browser.new_page(locale="ar-EG")
    page.on("console", lambda message: console_errors.append(message.text) if message.type == "error" else None)
    page.on("response", lambda response: response_errors.append(f"{response.status} {response.url}") if response.status >= 400 else None)
    page.goto(base_url + "/login", wait_until="networkidle")
    page.locator('input[name="username"]').fill(username)
    page.locator('input[name="password"]').fill(password)
    page.locator('button[type="submit"]').click()
    page.wait_for_load_state("networkidle")
    page.goto(base_url + "/rep-custody", wait_until="networkidle")
    row = page.locator("tr", has_text=fixture)
    assert row.count() == 1, "لم يظهر مندوب الاختبار"
    assert "مستحق للمندوب" in row.inner_text(), "الرصيد السالب بلا تسمية واضحة"
    row.get_by_role("link", name=fixture).click()
    page.wait_for_load_state("networkidle")
    body_text = page.locator("body").inner_text()
    assert "مستحق للمندوب" in body_text, f"الكارت بلا تسمية؛ {page.url}؛ {body_text[:400]!r}"
    assert "هذا وضع عمل مسموح" in body_text, "الكارت لا يشرح أن الرصيد السالب مسموح"
    assert not response_errors, f"موارد فاشلة: {response_errors}"
    assert not console_errors, f"أخطاء console: {console_errors}"
    browser.close()

print("v41 UI: negative custody is labelled as amount owed to the representative")
