#!/bin/bash
cd "$(dirname "$0")"
python3 -c "
h=open('bariq2.html',encoding='utf-8').read()
if 'nth-of-type(n+6)' not in h:
    h=h.replace('</style>','\n.pg:nth-of-type(n+6){display:none!important}\n</style>')
open('_b2.html','w',encoding='utf-8').write(h)"
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless --disable-gpu --no-pdf-header-footer \
  --print-to-pdf="/Users/karimmokhtar/المتوكل/عرض أسعار - شركة بريق.pdf" "file://$PWD/_b2.html" 2>/dev/null
python3 -c "
import re;print('عدد الصفحات:', re.findall(rb'/Count\s+(\d+)', open('/Users/karimmokhtar/المتوكل/عرض أسعار - شركة بريق.pdf','rb').read()))"
