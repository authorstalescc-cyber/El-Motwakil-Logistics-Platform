#!/usr/bin/env python3
"""فحص 07-C: طباعة A4 وبوابة المندوب على اتصال بطيء/منقطع.

الفحص يرفض قاعدة العمل، وينشئ fixtures داخل قاعدة اختبار disposable فقط.
تُحذف القاعدة المعزولة كاملة بعد التشغيل من أمر الاختبار الخارجي.
"""

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
    raise SystemExit("يرفض فحص الطباعة/بوابة المندوب العمل بلا ALMOTAWAKEL_TEST_DB معزولة")


def php(script: str) -> str:
    return subprocess.check_output(["php", "-r", script], cwd=root, text=True).strip()


fixture = json.loads(
    php(
        "require 'app/bootstrap.php';"
        "$suffix=substr(bin2hex(random_bytes(6)),0,12);"
        "$permitNo='__ux07c_'.$suffix;"
        "\\App\\Core\\Database::exec(\"INSERT INTO permits"
        "(permit_type,permit_no,permit_date,company,client,destination,agency,port,containers,status,note) "
        "VALUES('sader',?,CURRENT_DATE,'شركة اختبار الطباعة','عميل اختبار الطباعة',"
        "'وجهة اختبار','توكيل اختبار','ميناء اختبار',2,'open','نموذج قبول A4')\",[$permitNo]);"
        "$permitId=\\App\\Core\\Database::lastId();"
        "\\App\\Models\\Permit::createMovement($permitId);"
        "$invoiceId=\\App\\Models\\PermitInvoice::create(["
        "'permit_id'=>$permitId,'doc_kind'=>'invoice','taxable'=>0,"
        "'internal_no'=>'UX07C-'.$suffix,'invoice_date'=>date('Y-m-d'),"
        "'client'=>'عميل اختبار الطباعة','display_client'=>'عميل اختبار الطباعة',"
        "'company'=>'شركة اختبار الطباعة','destination'=>'وجهة اختبار',"
        "'permit_no'=>$permitNo,'permit_type'=>'sader','agency'=>'توكيل اختبار',"
        "'port'=>'ميناء اختبار','containers'=>2,'vat_rate'=>0.14,"
        "'note'=>'اختبار طباعة A4 بلا قص أو تداخل','items'=>["
        "['label'=>'النولون','qty'=>1,'price'=>1000,'freight'=>true],"
        "['label'=>'مصروف اختبار','qty'=>2,'price'=>125,'freight'=>false]]]);"
        "$repId=\\App\\Models\\Rep::create(['name'=>'__ux07c مندوب '.$suffix,'status'=>'active']);"
        "$repUser='ux07c_'.$suffix;$repPin='864219';"
        "$credential=\\App\\Core\\RepAuth::setCredentials($repId,$repUser,$repPin);"
        "if(empty($credential['ok'])){throw new RuntimeException($credential['msg']);}"
        "$c=require 'config/config.php';$owner=$c['default_user'];"
        "echo json_encode(['permit_id'=>$permitId,'invoice_id'=>$invoiceId,"
        "'rep_id'=>$repId,'rep_user'=>$repUser,'rep_pin'=>$repPin,"
        "'owner_user'=>$owner['username'],'owner_pass'=>$owner['password']],JSON_UNESCAPED_UNICODE);"
    )
)

capture_dir_text = os.environ.get("UX07_CAPTURE_DIR", "")
capture_dir = Path(capture_dir_text) if capture_dir_text else None
if capture_dir:
    capture_dir.mkdir(parents=True, exist_ok=True)
failure_shot = Path("/tmp/ux07c-print-portal-failure.png")


def same_origin(url: str) -> bool:
    return url.startswith(base_url + "/") or url == base_url


def assert_no_overflow(page, label: str) -> None:
    result = page.evaluate(
        """() => ({
          scroll: document.documentElement.scrollWidth,
          client: document.documentElement.clientWidth,
          offenders: Array.from(document.querySelectorAll('body *')).map(element => {
            const rect = element.getBoundingClientRect();
            return {tag: element.tagName, id: element.id,
              cls: String(element.className || '').slice(0, 70), left: rect.left, right: rect.right};
          }).filter(item => item.left < -1 || item.right > document.documentElement.clientWidth + 1).slice(0, 8)
        })"""
    )
    assert result["scroll"] <= result["client"] + 1, f"تمدد أفقي في {label}: {result}"


def assert_print_geometry(page, label: str, required_text: str) -> None:
    assert page.locator(".sheet").count() == 1, f"{label}: لا توجد ورقة طباعة واحدة"
    assert page.get_by_text(required_text, exact=False).first.is_visible(), f"{label}: المحتوى الأساسي غير ظاهر"
    assert page.locator(".noprint").first.evaluate("e => getComputedStyle(e).display") == "none", (
        f"{label}: أدوات الشاشة ظهرت في الطباعة"
    )
    geometry = page.locator(".sheet").evaluate(
        """sheet => {
          const outer = sheet.getBoundingClientRect();
          const selectors = 'table,.head,.info,.tiles,.totals,.contact-bar,.foot,.sec,.mv';
          const clipped = Array.from(sheet.querySelectorAll(selectors)).map(element => {
            const rect = element.getBoundingClientRect();
            return {tag: element.tagName, cls: String(element.className || '').slice(0, 80),
              left: rect.left, right: rect.right};
          }).filter(item => item.left < outer.left - 1 || item.right > outer.right + 1).slice(0, 8);
          const blocks = Array.from(sheet.querySelectorAll('.pad > .head, .pad > .sec, .pad > .details, '
            + '.pad > table.items, .pad > .totals, .pad > .note-box, .pad > .contact-bar, .pad > .foot'))
            .filter(element => getComputedStyle(element).display !== 'none')
            .map(element => { const rect = element.getBoundingClientRect(); return {
              cls: String(element.className || ''), top: rect.top, bottom: rect.bottom
            }; });
          const overlaps = [];
          for (let i = 1; i < blocks.length; i += 1) {
            if (blocks[i].top < blocks[i - 1].bottom - 1) overlaps.push([blocks[i - 1], blocks[i]]);
          }
          return {sheetWidth: outer.width, clipped, overlaps};
        }"""
    )
    assert geometry["sheetWidth"] > 700, f"{label}: عرض الورقة غير صالح للطباعة: {geometry}"
    assert not geometry["clipped"], f"{label}: عناصر مقصوصة أفقيًا: {geometry['clipped']}"
    assert not geometry["overlaps"], f"{label}: تداخل بين كتل الطباعة: {geometry['overlaps']}"


def pdf_page_count(data: bytes) -> int:
    return len(re.findall(rb"/Type\s*/Page\b", data))


def office_login(page) -> None:
    page.goto(base_url + "/login", wait_until="networkidle")
    page.locator('input[name="username"]').fill(fixture["owner_user"])
    page.locator('input[name="password"]').fill(fixture["owner_pass"])
    page.locator('button[type="submit"]').click()
    page.wait_for_load_state("networkidle")
    if "/work-date" in page.url:
        page.locator('input[name="work_date"]').fill("2026-07-20")
        page.locator('button[type="submit"]').first.click()
        page.wait_for_load_state("networkidle")


def rep_login(page) -> None:
    page.goto(base_url + "/m/login", wait_until="networkidle")
    page.locator('input[name="username"]').fill(fixture["rep_user"])
    page.locator('input[name="pin"]').fill(fixture["rep_pin"])
    page.get_by_role("button", name="ادخل", exact=True).click()
    page.wait_for_load_state("networkidle")
    assert re.search(r"/m(?:\?|$)", page.url), f"تعذر دخول المندوب: {page.url}"


console_errors: list[str] = []
response_errors: list[str] = []

try:
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        try:
            # مستندا الطباعة يستخدمان جلسة المكتب نفسها.
            office_context = browser.new_context(locale="ar-EG", viewport={"width": 1280, "height": 900})
            office_page = office_context.new_page()
            office_page.on(
                "console", lambda message: console_errors.append(message.text) if message.type == "error" else None
            )
            office_page.on(
                "response",
                lambda response: response_errors.append(f"{response.status} {response.url}")
                if same_origin(response.url) and response.status >= 400 else None,
            )
            office_page.route("https://api.qrserver.com/**", lambda route: route.abort())
            office_login(office_page)

            print_cases = [
                (f"/permits/{fixture['permit_id']}/print", "permit", "كشف مراجعة الإذن"),
                (f"/permits/invoice/{fixture['invoice_id']}", "invoice", "عميل اختبار الطباعة"),
            ]
            for path, name, required_text in print_cases:
                office_page.goto(base_url + path, wait_until="domcontentloaded")
                if name == "invoice":
                    office_page.wait_for_function(
                        """() => { const image = document.querySelector('.qr img');
                          return image && image.complete && image.naturalWidth === 0
                            && getComputedStyle(image.parentElement).display === 'none'; }"""
                    )
                office_page.emulate_media(media="print")
                office_page.evaluate("window.dispatchEvent(new Event('beforeprint'))")
                assert_print_geometry(office_page, name, required_text)
                pdf = office_page.pdf(format="A4", print_background=True, prefer_css_page_size=True)
                pages = pdf_page_count(pdf)
                assert pages == 1, f"{name}: خرج المستند في {pages} صفحات بدل صفحة A4 واحدة"
                if capture_dir:
                    (capture_dir / f"{name}-a4.pdf").write_bytes(pdf)
                    office_page.locator(".sheet").screenshot(path=str(capture_dir / f"{name}-print.png"))
            office_context.close()

            # بوابة المندوب: 320px + شبكة بطيئة ثم انقطاع قبل الطلب وأثناءه.
            rep_context = browser.new_context(locale="ar-EG", viewport={"width": 320, "height": 800})
            rep_page = rep_context.new_page()
            rep_page.on(
                "console", lambda message: console_errors.append(message.text) if message.type == "error" else None
            )
            rep_page.on(
                "response",
                lambda response: response_errors.append(f"{response.status} {response.url}")
                if same_origin(response.url) and response.status >= 400 else None,
            )
            rep_login(rep_page)

            cdp = rep_context.new_cdp_session(rep_page)
            cdp.send("Network.enable")
            cdp.send(
                "Network.emulateNetworkConditions",
                {"offline": False, "latency": 350, "downloadThroughput": 96_000, "uploadThroughput": 48_000},
            )
            rep_page.goto(base_url + "/m", wait_until="domcontentloaded", timeout=30_000)
            rep_page.locator(".misc-cta").first.wait_for(state="visible")
            assert_no_overflow(rep_page, "بوابة المندوب 320px على اتصال بطيء")
            cta = rep_page.locator(".misc-cta").first.bounding_box()
            assert cta and cta["x"] >= -1 and cta["x"] + cta["width"] <= 321, "الإجراء الأساسي مقصوص"
            assert cta["height"] >= 44, "الإجراء الأساسي أصغر من هدف لمس 44px"

            cdp.send(
                "Network.emulateNetworkConditions",
                {"offline": False, "latency": 0, "downloadThroughput": -1, "uploadThroughput": -1},
            )
            rep_page.goto(base_url + "/m/misc", wait_until="networkidle")
            rep_page.locator('input[name="note"]').fill("مواصلات اختبار الانقطاع")
            rep_page.locator('input[name="unit_amount"]').fill("125")
            token = rep_page.locator('input[name="client_token"]').input_value()
            assert re.fullmatch(r"[a-f0-9]{32,64}", token), "رمز منع التكرار غير صالح"

            # انقطاع معروف قبل الإرسال: لا طلب، لا فقد قيم، والزر يظل متاحًا.
            rep_context.set_offline(True)
            rep_page.locator("#save").click()
            rep_page.locator("#netState.offline").wait_for(state="visible")
            assert "المسودة محفوظة" in rep_page.locator("#netText").inner_text()
            assert rep_page.locator('input[name="note"]').input_value() == "مواصلات اختبار الانقطاع"
            assert rep_page.locator('input[name="unit_amount"]').input_value() == "125"
            assert rep_page.locator("#save").is_enabled(), "زر الإرسال ظل معطّلًا بعد الانقطاع"
            draft = rep_page.evaluate("key => localStorage.getItem(key)", rep_page.locator("#f").get_attribute("data-draft-key"))
            assert draft and "مواصلات اختبار الانقطاع" in draft, "المسودة لم تُحفظ محليًا"

            # انقطاع أثناء fetch: الواجهة تستعيد حالتها وتعرض إعادة المحاولة.
            rep_context.set_offline(False)
            rep_page.locator("#netState").wait_for(state="hidden")
            aborted = {"done": False}

            def abort_first_post(route, request):
                if request.method == "POST" and request.url.endswith("/m/misc") and not aborted["done"]:
                    aborted["done"] = True
                    route.abort("connectionfailed")
                else:
                    route.continue_()

            rep_page.route("**/*", abort_first_post)
            rep_page.locator("#save").click()
            assert aborted["done"], "لم يُحاكَ انقطاع الطلب"
            rep_page.wait_for_function("document.querySelector('#save') && !document.querySelector('#save').disabled")
            rep_page.locator("#netState.offline").wait_for(state="visible")
            assert rep_page.locator("#save").is_enabled(), "زر الإرسال لم يستعد حالته بعد فشل الطلب"
            assert rep_page.locator('input[name="note"]').input_value() == "مواصلات اختبار الانقطاع"
            assert rep_page.locator('input[name="client_token"]').input_value() == token, "تغيّر رمز منع التكرار"

            # الشبكة عادت: نفس المحاولة تنجح مرة واحدة وتزيل المسودة.
            rep_page.unroute("**/*", abort_first_post)
            rep_page.locator("#retryBtn").click()
            rep_page.wait_for_url(re.compile(r"/m\?tab=entries&status=pending"), timeout=15_000)
            assert "مواصلات اختبار الانقطاع" in rep_page.locator("body").inner_text()
            count = int(
                php(
                    "require 'app/bootstrap.php';echo (int)\\App\\Core\\Database::scalar("
                    f"\"SELECT COUNT(*) FROM rep_submissions WHERE rep_id={int(fixture['rep_id'])} "
                    f"AND client_token='{token}'\");"
                )
            )
            assert count == 1, f"إعادة المحاولة أنشأت {count} سجلات بدل سجل واحد"
            draft_after = rep_page.evaluate(
                "key => localStorage.getItem(key)", rep_page.locator("body").evaluate("() => 'alm:m:draft:misc:'")
                + str(fixture["rep_id"]) + ":0"
            )
            assert draft_after is None, "المسودة لم تُحذف بعد تأكيد نجاح التسجيل"
            assert_no_overflow(rep_page, "نتيجة إرسال المندوب 320px")
            if capture_dir:
                rep_page.screenshot(path=str(capture_dir / "rep-portal-320.png"), full_page=True)
            rep_context.close()
        finally:
            browser.close()
except Exception:
    try:
        if "office_page" in locals() and not office_page.is_closed():
            office_page.screenshot(path=str(failure_shot), full_page=True)
        elif "rep_page" in locals() and not rep_page.is_closed():
            rep_page.screenshot(path=str(failure_shot), full_page=True)
    except Exception:
        pass
    raise

assert not response_errors, f"استجابات HTTP فاشلة: {response_errors}"
expected_network_console = {"Failed to load resource: net::ERR_FAILED", "Failed to load resource: net::ERR_CONNECTION_FAILED"}
unexpected_console = [message for message in console_errors if message not in expected_network_console]
assert not unexpected_console, f"أخطاء console غير متوقعة: {unexpected_console}"
print("UX07-C: A4 permit/invoice + 320px rep portal slow/offline/retry passed")
