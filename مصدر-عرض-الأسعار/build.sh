#!/bin/bash
# يبني عرض بريق. لو bariq-logo.png موجود بيتحط في الغلاف، ولو مش موجود بيتشال بلا ما يكسر التصميم.
cd "$(dirname "$0")"
python3 - <<'PY'
import os,re
h=open('bariq.html',encoding='utf-8').read()
has=os.path.exists('bariq-logo.png')
if not has:
    h=re.sub(r'<span class="ic"><img src="bariq-logo.png" alt=""></span>','<span class="ic" style="display:none"></span>',h)
open('_build.html','w',encoding='utf-8').write(h)
print('لوجو بريق:', 'موجود ✅' if has else 'مش موجود — الغلاف بلا لوجو')
PY
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless --disable-gpu --no-pdf-header-footer \
  --print-to-pdf="/Users/karimmokhtar/المتوكل/عرض أسعار - شركة بريق.pdf" "file://$PWD/_build.html" 2>/dev/null
python3 -c "
import re;print('عدد الصفحات:', re.findall(rb'/Count\s+(\d+)', open('/Users/karimmokhtar/المتوكل/عرض أسعار - شركة بريق.pdf','rb').read()))"
