#!/usr/bin/env python3
"""قِس صفحات المكتب الحرجة من متصفح حقيقي على قاعدة اختبار معزولة."""

import argparse
import json
import math
import os
import platform
import re
import subprocess
import sys
from datetime import datetime, timezone
from importlib.metadata import version
from pathlib import Path
from urllib.parse import quote

from playwright.sync_api import sync_playwright


root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("--samples", type=int, default=5, help="عدد العينات لكل صفحة (3–20)")
parser.add_argument("--output", default="", help="ملف JSON اختياري؛ الافتراضي stdout")
parser.add_argument("--fixture-name", default="fresh_install", help="اسم وصفي ثابت لعينة البيانات")
parser.add_argument("--assert-budgets", action="store_true", help="أخفق عند تجاوز ميزانيات smoke المؤقتة")
args = parser.parse_args()
if not 3 <= args.samples <= 20:
    raise SystemExit("--samples يجب أن يكون بين 3 و20")

database = os.environ.get("ALMOTAWAKEL_TEST_DB", "")
base_url = os.environ.get("ALMOTAWAKEL_E2E_URL", "")
if not re.fullmatch(r"almotawakel_test_[a-z0-9_]{6,48}", database):
    raise SystemExit("القياس يتطلب ALMOTAWAKEL_TEST_DB معزولة")
if not base_url.startswith(("http://127.0.0.1:", "http://localhost:")):
    raise SystemExit("القياس يتطلب ALMOTAWAKEL_E2E_URL محليًا")
if os.environ.get("ALMOTAWAKEL_PERF") != "1":
    raise SystemExit("فعّل القياس صراحة عبر ALMOTAWAKEL_PERF=1")


def clean_environment() -> dict[str, str]:
    environment = os.environ.copy()
    for key in ("ALMOTAWAKEL_PERF", "ALMOTAWAKEL_PERF_LOG", "ALMOTAWAKEL_PERF_SLOW_MS"):
        environment.pop(key, None)
    return environment


def php(script: str) -> str:
    return subprocess.check_output(
        ["php", "-r", script], cwd=root, env=clean_environment(), text=True
    ).strip()


def nearest_p95(values: list[float]) -> float:
    ordered = sorted(values)
    return ordered[max(0, math.ceil(len(ordered) * 0.95) - 1)]


def server_metric(header: str, name: str) -> float:
    match = re.search(rf"(?:^|,)\s*{re.escape(name)};dur=([\d.]+)", header)
    if not match:
        raise AssertionError(f"Server-Timing لا يحتوي {name}: {header!r}")
    return float(match.group(1))


credentials = json.loads(php(
    "$c=require 'config/config.php';"
    "echo json_encode([$c['default_user']['username'],$c['default_user']['password']]);"
))
metadata = json.loads(php(
    "require 'app/bootstrap.php';"
    "$tables=['permits','bawalis','permit_stages','manual_expenses','client_payments','reps'];"
    "$counts=[];foreach($tables as $t){$counts[$t]=(int)\\App\\Core\\Database::scalar('SELECT COUNT(*) FROM `'.$t.'`');}"
    "echo json_encode(['php'=>PHP_VERSION,'mysql'=>(string)\\App\\Core\\Database::scalar('SELECT VERSION()'),'rows'=>$counts]);"
))
try:
    commit = subprocess.check_output(
        ["git", "rev-parse", "--short", "HEAD"], cwd=root, text=True
    ).strip()
except subprocess.CalledProcessError:
    commit = "unknown"

pages = [
    ("dashboard", "/"),
    ("permits", "/permits"),
    ("expenses", "/expenses"),
    ("invoices", "/invoices"),
    ("closing", "/closing"),
]
measurements: dict[str, dict[str, object]] = {}
console_errors: list[str] = []
response_errors: list[str] = []

with sync_playwright() as playwright:
    browser = playwright.chromium.launch(headless=True)
    browser_version = browser.version
    context = browser.new_context(locale="ar-EG", viewport={"width": 1440, "height": 900})
    page = context.new_page()
    page.on("console", lambda msg: console_errors.append(msg.text) if msg.type == "error" else None)
    page.on("response", lambda response: response_errors.append(f"{response.status} {response.url}") if response.status >= 400 else None)

    page.goto(base_url + "/login", wait_until="networkidle")
    page.locator('input[name="username"]').fill(credentials[0])
    page.locator('input[name="password"]').fill(credentials[1])
    page.locator('button[type="submit"]').click()
    page.wait_for_load_state("networkidle")
    if "/work-date" in page.url:
        page.locator('input[name="work_date"]').fill("2026-07-20")
        page.locator('button[type="submit"]').first.click()
        page.wait_for_load_state("networkidle")

    for label, path in pages:
        samples = []
        for _ in range(args.samples):
            response = page.goto(base_url + path, wait_until="networkidle")
            assert response is not None and response.status == 200, f"فشل تحميل {path}"
            timing = response.headers.get("server-timing", "")
            queries = response.headers.get("x-perf-queries", "")
            slow_queries = response.headers.get("x-perf-slow-queries", "")
            response_bytes = response.headers.get("x-perf-response-bytes", "")
            assert queries.isdigit(), f"هيدر عدد الاستعلامات مفقود من {path}"
            assert slow_queries.isdigit(), f"هيدر الاستعلامات البطيئة مفقود من {path}"
            navigation_ms = page.evaluate(
                "performance.getEntriesByType('navigation')[0]?.duration || 0"
            )
            samples.append({
                "browser_ms": round(float(navigation_ms), 2),
                "app_ms": server_metric(timing, "app"),
                "db_ms": server_metric(timing, "db"),
                "queries": int(queries),
                "slow_queries": int(slow_queries),
                "html_bytes": int(response_bytes) if response_bytes.isdigit() else len(response.body()),
            })
        measurements[label] = {
            "path": path,
            "samples": len(samples),
            "p95_browser_ms": round(nearest_p95([item["browser_ms"] for item in samples]), 2),
            "p95_app_ms": round(nearest_p95([item["app_ms"] for item in samples]), 2),
            "p95_db_ms": round(nearest_p95([item["db_ms"] for item in samples]), 2),
            "max_queries": max(item["queries"] for item in samples),
            "max_slow_queries": max(item["slow_queries"] for item in samples),
            "max_html_bytes": max(item["html_bytes"] for item in samples),
        }

    # تحقق UX ملازم للقياس: الخيارات الثقيلة لا تُحمّل قبل فتح الأداة، ثم تعمل
    # عند الطلب؛ وصفحة المتابعة لا تعيد مئات البطاقات دفعة واحدة.
    page.goto(base_url + "/", wait_until="networkidle")
    with page.expect_response(lambda response: "/dashboard/quick-options" in response.url) as quick_info:
        page.locator("#quickEntryOpen").click()
    quick_response = quick_info.value
    assert quick_response.status == 200, "فشل تحميل خيارات الإدخال السريع عند الطلب"
    quick_payload = quick_response.json()
    assert quick_payload.get("ok") is True and isinstance(quick_payload.get("permits"), list)
    assert isinstance(quick_payload.get("cards"), list)
    assert isinstance(quick_payload.get("receipts"), list)
    assert isinstance(quick_payload.get("expenses"), list)
    assert page.locator("#qeReceiptNames option").count() == len(quick_payload["receipts"])
    assert page.locator("#qeExpenseNames option").count() == len(quick_payload["expenses"])
    page.locator(".qe-permit-pick").first.click()
    page.locator("#qePermitSearch").wait_for(state="visible")
    page.wait_for_function("!document.querySelector('#qePermitSearch').disabled")
    option_count = page.locator("[data-permit-id]").count()
    assert option_count == min(120, len(quick_payload["permits"]))

    page.goto(base_url + "/permits", wait_until="networkidle")
    permit_cards = page.locator("#pvFollowGrid .dkt").count()
    assert permit_cards <= 48, f"صفحة المتابعة حمّلت {permit_cards} بطاقة بدل صفحة محدودة"
    open_option = next((item for item in quick_payload["permits"] if item.get("status") == "open"), None)
    if open_option:
        page.goto(base_url + "/permits?q=" + quote(str(open_option["no"])), wait_until="networkidle")
        assert page.locator("#pvFollowGrid .dkt").count() >= 1, "البحث الخادمي لم يجد الإذن المفتوح"
    browser.close()

if console_errors or response_errors:
    raise AssertionError(f"console={console_errors}; responses={response_errors}")

report = {
    "measured_at": datetime.now(timezone.utc).isoformat(),
    "commit": commit,
    "dirty_worktree": subprocess.run(["git", "diff", "--quiet"], cwd=root).returncode != 0,
    "runtime": {
        "os": platform.platform(),
        "python": sys.version.split()[0],
        "playwright": version("playwright"),
        "browser": browser_version,
    },
    "fixture": {"name": args.fixture_name, **metadata},
    "samples_per_page": args.samples,
    "pages": measurements,
    "ux_checks": {
        "quick_options_loaded_on_demand": True,
        "quick_options_count": len(quick_payload["permits"]),
        "quick_options_render_cap": 120,
        "permits_page_card_cap": 48,
    },
}
if args.assert_budgets:
    query_budgets = {"dashboard": 20, "permits": 8, "expenses": 8, "invoices": 8, "closing": 40}
    violations = []
    for label, page_result in measurements.items():
        if page_result["max_queries"] > query_budgets[label]:
            violations.append(
                f"{label}: queries={page_result['max_queries']} > {query_budgets[label]}"
            )
        if page_result["max_slow_queries"] > 0:
            violations.append(f"{label}: slow_queries={page_result['max_slow_queries']} > 0")
        if page_result["p95_app_ms"] > 500:
            violations.append(f"{label}: p95_app_ms={page_result['p95_app_ms']} > 500")
        if page_result["max_html_bytes"] > 200_000:
            violations.append(f"{label}: html={page_result['max_html_bytes']} > 200000")
    report["budgets"] = {
        "query_max": query_budgets,
        "slow_query_ms": float(os.environ.get("ALMOTAWAKEL_PERF_SLOW_MS", "100")),
        "slow_queries_max": 0,
        "p95_app_ms_max": 500,
        "html_bytes_max": 200_000,
        "passed": not violations,
        "violations": violations,
    }
encoded = json.dumps(report, ensure_ascii=False, indent=2) + "\n"
if args.output:
    output = Path(args.output).resolve()
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(encoded, encoding="utf-8")
    print(f"performance baseline written: {output}")
else:
    print(encoded, end="")
if args.assert_budgets and report["budgets"]["violations"]:
    raise SystemExit("performance budgets failed: " + "; ".join(report["budgets"]["violations"]))
