#!/usr/bin/env python3
"""ابدأ خادم PHP محليًا، شغّل حزمة المتصفح، ثم أوقفه دائمًا."""

import os
import re
import socket
import subprocess
import sys
import tempfile
import time
from pathlib import Path
from urllib.parse import urlparse


root = Path(__file__).resolve().parents[1]
db_name = os.environ.get("ALMOTAWAKEL_TEST_DB", "")
if not re.fullmatch(r"almotawakel_test_[a-z0-9_]{6,48}", db_name):
    raise SystemExit("حارس DSN: اضبط ALMOTAWAKEL_TEST_DB على قاعدة اختبار معزولة")

base_url = os.environ.get("ALMOTAWAKEL_E2E_URL", "http://127.0.0.1:8795")
parsed = urlparse(base_url)
if parsed.scheme != "http" or parsed.hostname not in {"127.0.0.1", "localhost"} or not parsed.port:
    raise SystemExit("حارس الخادم: ALMOTAWAKEL_E2E_URL يجب أن يكون HTTP محليًا مع port صريح")

environment = os.environ.copy()
environment["ALMOTAWAKEL_E2E_URL"] = base_url
command = ["php", "-S", f"{parsed.hostname}:{parsed.port}", "-t", "public"]


def wait_until_ready(process: subprocess.Popen, timeout: float = 30.0) -> None:
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        if process.poll() is not None:
            raise RuntimeError(f"توقف خادم PHP مبكرًا (exit {process.returncode})")
        try:
            with socket.create_connection((parsed.hostname, parsed.port), timeout=0.5):
                return
        except OSError:
            time.sleep(0.15)
    raise RuntimeError("انتهت مهلة انتظار خادم PHP المحلي")


with tempfile.TemporaryFile(mode="w+t", encoding="utf-8") as server_log:
    server = subprocess.Popen(command, cwd=root, env=environment, stdout=server_log, stderr=subprocess.STDOUT)
    result_code = 1
    try:
        wait_until_ready(server)
        arguments = list(sys.argv[1:])
        performance_mode = "--performance" in arguments
        if performance_mode:
            arguments.remove("--performance")
            if environment.get("ALMOTAWAKEL_PERF") != "1":
                raise RuntimeError("وضع الأداء يتطلب ALMOTAWAKEL_PERF=1")
        script = "measure_performance_ui.py" if performance_mode else "run_browser_verifiers.py"
        runner = [sys.executable, str(root / "bin" / script), *arguments]
        result_code = subprocess.run(runner, cwd=root, env=environment).returncode
    except Exception as error:
        print(f"تعذّر تشغيل حزمة المتصفح: {error}", file=sys.stderr)
    finally:
        server.terminate()
        try:
            server.wait(timeout=5)
        except subprocess.TimeoutExpired:
            server.kill()
            server.wait(timeout=5)
        if result_code != 0:
            server_log.seek(0)
            lines = server_log.readlines()
            if lines:
                print("\nآخر سجل لخادم PHP:", file=sys.stderr)
                print("".join(lines[-40:]), file=sys.stderr)

raise SystemExit(result_code)
