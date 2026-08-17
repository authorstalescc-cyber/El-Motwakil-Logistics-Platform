<?php
/**
 * مُصيّرا صفوف الداشبورد — **نسخة واحدة** تخدم الصفحة ونوافذ التفاصيل.
 *
 * ⚠ لازم يفضلوا مشتركين: لو الصفحة رسمت الصف بطريقة والنافذة بطريقة تانية،
 *    المالك بيحسّ إنه دخل مكان تاني، والاتنين بيفترقوا مع أول تعديل. عشان
 *    كده الملف ده بيتضمّن من `index.php` و`_drill.php` مع بعض.
 *
 * <?= icon('alert', 14) ?> الحارس `function_exists` مقصود — الملفان ممكن يتحمّلوا في نفس الطلب.
 */
use App\Models\Permit;

if (!function_exists('dash_permit_row')) {
    /** صف إذن — `$ready=true` للجاهز للفوترة (بزر فوترة)، وإلا للمفتوح. */
    function dash_permit_row(array $p, bool $ready): void
    {
        $th = Permit::theme($p['permit_type']);
        $meta = [];
        if (!empty($p['ct_label'])) { $meta[] = ['icon' => 'package', 'text' => $p['ct_label']]; }
        $meta[] = $ready
            ? ['icon' => 'truck', 'text' => intf($p['bills']) . ' بوليصة']
            : ['icon' => 'layers', 'text' => intf($p['stages']) . ' بند'];
        $meta[] = ['icon' => 'calendar', 'text' => dfmt($ready ? $p['permit_date'] : $p['last_activity'])];
        partial('row_item', [
            'title'  => $p['party'],
            'ref'    => !empty($p['permit_no']) ? '#' . $p['permit_no'] : null,
            'glyph'  => $th['arrow'],
            'tone'   => $th['cls'],
            'url'    => url('permits/' . $p['id']),
            'meta'   => $meta,
            'action' => $ready
                ? ['label' => 'فوترة', 'url' => url('permits/' . $p['id'] . '/invoice')]
                : null,
        ]);
    }
}

if (!function_exists('dash_feed_row')) {
    /** صف مصروف/إيصال من تغذية اليوم. */
    function dash_feed_row(array $f): void
    {
        $rc = !empty($f['is_receipt']);
        $meta = [];
        if ($f['ref_kind'] === 'permit' && !empty($f['ref'])) {
            $meta[] = ['icon' => 'folder', 'text' => 'إذن ' . $f['ref']];
        } elseif ($f['ref_kind'] === 'bill' && !empty($f['ref'])) {
            $meta[] = ['icon' => 'truck', 'text' => 'بوليصة ' . $f['ref']];
        } else {
            $meta[] = ['icon' => 'wallet', 'text' => 'يدوي'];
        }
        if (!empty($f['owner'])) { $meta[] = ['icon' => 'user', 'text' => $f['owner']]; }
        if (!empty($f['note'])) { $meta[] = ['icon' => 'file-text', 'text' => $f['note']]; }
        partial('row_item', [
            'title'  => $f['label'] ?: ($rc ? 'إيصال' : 'مصروف'),
            'icon'   => $rc ? 'clipboard' : 'trending-down',
            'meta'   => $meta,
            'amount' => money($f['amount'], false),
            'amount_tone' => $rc ? 'rc' : 'ex',
            'sub'    => dfmt($f['d']),
        ]);
    }
}

if (!function_exists('dash_car_row')) {
    /**
     * صف «عربية خرجت النهاردة» — بوليصة واحدة = نقلة سيارة واحدة.
     *
     * ⚠️ **البطل هو رقم العربية والسائق مش المبلغ.** سؤال المالك «خرج كام
     *    عربية» سؤال تشغيلي: مين خرج وراح فين. النولون بيتعرض على الجنب
     *    كسياق، مش كعنوان.
     */
    function dash_car_row(array $c): void
    {
        $meta = [];
        if (($c['driver'] ?? '') !== '')     { $meta[] = ['icon' => 'user', 'text' => $c['driver']]; }
        if (($c['party'] ?? '') !== '')      { $meta[] = ['icon' => 'building', 'text' => $c['party']]; }
        if (($c['destination'] ?? '') !== '') { $meta[] = ['icon' => 'map-pin', 'text' => $c['destination']]; }

        $chips = [];
        $cnt = (int) ($c['container_count'] ?? 0);
        if ($cnt > 0) {
            $chips[] = ['text' => intf($cnt) . '×' . (($c['container_type'] ?? '') ?: 'حاوية')];
        }

        partial('row_item', [
            'title'  => ($c['car_no'] ?? '') !== '' ? $c['car_no'] : 'بلا رقم عربية',
            'ref'    => ($c['bill_number'] ?? '') !== '' ? '#' . $c['bill_number'] : null,
            'icon'   => 'truck',
            'url'    => url('bawalis/' . (int) $c['id']),
            'meta'   => $meta,
            'chips'  => $chips,
            'amount' => money0($c['freight'] ?? 0, false),
            'sub'    => 'نولون',
        ]);
    }
}

if (!function_exists('dash_readiness_row')) {
    /**
     * صف جاهزية الإذن — «خلص شغله» أو «ناقصه إيصالات».
     *
     * ⚠️ **الحكم والسبب بيتقروا من `PermitReadiness` مش بيتحسبوا هنا.** لو
     *    الشاشة كتبت الشرط بإيدها كانت هتفترق عن الكارت مع أول تعديل في
     *    التعريف — وده عطل «كارت يقول رقم وكشف يقول رقم تاني».
     */
    function dash_readiness_row(array $r): void
    {
        $th   = Permit::theme($r['permit_type']);
        $meta = [];
        if (($r['party'] ?? '') !== '' && $r['party'] !== '—') {
            $meta[] = ['icon' => 'building', 'text' => $r['party']];
        }
        $meta[] = ['icon' => 'truck', 'text' => intf((int) $r['cars']) . ' عربية'];
        if (!empty($r['permit_date'])) { $meta[] = ['icon' => 'calendar', 'text' => dfmt($r['permit_date'])]; }

        $ready = ($r['verdict'] ?? '') === \App\Models\PermitReadiness::READY;
        partial('row_item', [
            'title'  => ($r['permit_type'] === 'warid' ? 'شهادة ' : 'إذن ') . ($r['permit_no'] ?: '#' . (int) $r['id']),
            'icon'   => 'folder',
            'tone'   => $th['tone'] ?? null,
            'url'    => url('permits/' . (int) $r['id']),
            'meta'   => $meta,
            'chips'  => [['icon' => $ready ? 'check-circle' : 'alert', 'text' => (string) $r['reason']]],
            'amount' => intf((int) $r['conts_done']) . '/' . intf((int) $r['conts_need']),
            'sub'    => 'حاويات خرجت',
        ]);
    }
}

if (!function_exists('dash_opened_row')) {
    /** صف «إذن اتفتح النهاردة» — التاريخ من `audit_logs.work_date` مش `created_at`. */
    function dash_opened_row(array $o): void
    {
        $th   = Permit::theme($o['permit_type']);
        $meta = [];
        if (($o['company'] ?? '') !== '') { $meta[] = ['icon' => 'building', 'text' => $o['company']]; }
        if (($o['client'] ?? '') !== '')  { $meta[] = ['icon' => 'user', 'text' => $o['client']]; }
        if (($o['user_name'] ?? '') !== '') { $meta[] = ['icon' => 'edit', 'text' => $o['user_name']]; }

        partial('row_item', [
            'title' => ($o['permit_type'] === 'warid' ? 'شهادة ' : 'إذن ') . ($o['permit_no'] ?: '#' . (int) $o['id']),
            'icon'  => 'folder',
            'tone'  => $th['tone'] ?? null,
            'url'   => url('permits/' . (int) $o['id']),
            'meta'  => $meta,
            'chips' => [['text' => $o['status'] === 'closed' ? 'مقفول' : 'مفتوح',
                         'tone' => $o['status'] === 'closed' ? null : 'wait']],
        ]);
    }
}

if (!function_exists('dash_rep_rows')) {
    /**
     * صفوف «عهدة كل مندوب النهاردة».
     *
     * ⚠️ **الرقم الأساسي `pocket` مش `balance`** — «فاضل معاه» = عهدة اليوم
     *    ناقص المصروف المعتمد **وناقص اللي دفعه ولسه مستنّي اعتماد**. فلوس
     *    البند المستنّي خرجت من إيده فعلاً، فعرض `balance` كان بيقول إن معاه
     *    فلوس هو أصلاً صرفها (القاعدة الموحَّدة في كل شاشات العهدة).
     * ⚠️ **ولا رقم تراكمي هنا** — التراكمي مكانه `/rep-custody`.
     *
     * ⚠️⚠️ **الحكم («سوّى» / «لسه معاه») بييجي من `RepCustody::dayVerdict()`
     *    مش بيتكتب هنا** — طلب المالك (2026-08-17). الصف كان بيعرض
     *    `نزل · صرف · فاضل` وبس، والمدير كان لازم يطرح في دماغه عشان يعرف
     *    المندوب سوّى ولا لأ. لو الشاشة كتبت الشرط بإيدها كانت هتفترق عن
     *    كارت الإجمالي ونافذة التفاصيل مع أول تعديل في التعريف.
     */
    function dash_rep_rows(array $rows, array $names): void
    {
        $byId = [];
        foreach ($names as $n) { $byId[(int) $n['id']] = (string) $n['name']; }

        foreach ($rows as $rid => $r) {
            $rid  = (int) $rid;
            $dep  = (float) ($r['deposits'] ?? 0);
            $sp   = (float) ($r['spent'] ?? 0);
            $poc  = (float) ($r['pocket'] ?? 0);
            $v    = \App\Models\RepCustody::dayVerdict($r);

            $meta = [['icon' => 'wallet', 'text' => 'نزل ' . money0($dep, false)],
                     ['icon' => 'trending-down', 'text' => 'صرف ' . money0($sp, false)]];

            /* الحكم بالكلمة **والمبلغ معاه** — «لسه معاه» لوحدها ما بتقولش كام. */
            $chip = ['icon' => $v['key'] === 'settled' ? 'check-circle' : 'alert', 'text' => $v['text']];
            if ($v['amount'] > 0.005) { $chip['text'] .= ' ' . money0($v['amount'], false); }

            partial('row_item', [
                'title'  => $byId[$rid] ?? ('مندوب #' . $rid),
                'icon'   => 'user',
                'url'    => url('rep-custody/' . $rid),
                'meta'   => $meta,
                'chips'  => [$chip],
                'amount' => money0($poc, false),
                'amount_tone' => $poc < 0 ? 'ex' : null,
                'sub'    => 'فاضل معاه',
            ]);
        }
    }
}
