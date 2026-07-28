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

if (!function_exists('dash_touched_row')) {
    /**
     * صف «إذن اتحرّك عليه فلوس النهاردة».
     *
     * ⚠️ **المستنّي بيتعرض لوحده بشارة صفرا** — طلب المالك إنه يشوف اللي اتضاف
     *    «حتى لو لم يتم اعتماده»، وخلطه بالمعتمَد كان هيخلّيه يفتكر إن البند
     *    اتراجع وخلص. الشارة هي اللي بتقول «ده لسه مستنّي قرارك».
     */
    function dash_touched_row(array $t): void
    {
        $th   = Permit::theme($t['permit_type']);
        $meta = [];
        if ($t['rc_cnt'] > 0) {
            $meta[] = ['icon' => 'clipboard', 'text' => intf($t['rc_cnt']) . ' إيصال · ' . money0($t['rc_val'], false)];
        }
        if ($t['ex_cnt'] > 0) {
            $meta[] = ['icon' => 'trending-down', 'text' => intf($t['ex_cnt']) . ' مصروف · ' . money0($t['ex_val'], false)];
        }
        if (($t['company'] ?? '') !== '') { $meta[] = ['icon' => 'building', 'text' => $t['company']]; }

        $chips = [];
        if ($t['pend_cnt'] > 0) {
            $chips[] = ['text' => intf($t['pend_cnt']) . ' مستنّي اعتمادك · ' . money0($t['pend_val'], false), 'tone' => 'wait'];
        }
        if ($t['by_rep'] > 0) { $chips[] = ['text' => 'من مندوب', 'tone' => 'ex']; }

        partial('row_item', [
            'title'  => ($t['permit_type'] === 'warid' ? 'شهادة ' : 'إذن ') . ($t['permit_no'] ?: '#' . $t['id']),
            'icon'   => 'folder',
            'tone'   => $th['tone'] ?? null,
            'url'    => url('permits/' . $t['id']),
            'meta'   => $meta,
            'chips'  => $chips,
            'amount' => money0($t['rc_val'] + $t['ex_val'] + $t['pend_val'], false),
            'sub'    => intf($t['items']) . ' بند',
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
     */
    function dash_rep_rows(array $rows, array $names): void
    {
        $byId = [];
        foreach ($names as $n) { $byId[(int) $n['id']] = (string) $n['name']; }

        foreach ($rows as $rid => $r) {
            $rid  = (int) $rid;
            $dep  = (float) ($r['deposits'] ?? 0);
            $sp   = (float) ($r['spent'] ?? 0);
            $pend = (float) ($r['pending'] ?? 0);
            $poc  = (float) ($r['pocket'] ?? 0);

            $meta = [['icon' => 'wallet', 'text' => 'نزل ' . money0($dep, false)],
                     ['icon' => 'trending-down', 'text' => 'صرف ' . money0($sp, false)]];

            $chips = [];
            if ($pend > 0) { $chips[] = ['text' => 'مستنّي ' . money0($pend, false), 'tone' => 'wait']; }
            if ($poc < 0)  { $chips[] = ['text' => 'مدفوع من جيبه', 'tone' => 'ex']; }

            partial('row_item', [
                'title'  => $byId[$rid] ?? ('مندوب #' . $rid),
                'icon'   => 'user',
                'url'    => url('rep-custody/' . $rid),
                'meta'   => $meta,
                'chips'  => $chips,
                'amount' => money0($poc, false),
                'amount_tone' => $poc < 0 ? 'ex' : null,
                'sub'    => 'فاضل معاه',
            ]);
        }
    }
}
