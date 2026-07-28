<?php
/**
 * لوحة المراقبة الأمنية — عرض فقط (للمالك).
 * مفيش أي فورم هنا عن قصد: كل إجراء بيتعمل من /settings و/rep-custody.
 */
/* ⚠️ `$sec` مش `$data` — شوف الشرح في SecurityController::index */
$d = $sec ?? ['ready' => false];
$hours = $hours ?? 24;
$t = $d['totals'] ?? ['failed' => 0, 'ok' => 0, 'sources' => 0, 'badSources' => 0];

/** وقت مختصر «من ساعة» / «امبارح» */
$ago = static function (string $at): string {
    if ($at === '') { return '—'; }
    $s = time() - strtotime($at);
    if ($s < 0)      { return substr($at, 11, 5); }
    if ($s < 60)     { return 'دلوقتي'; }
    if ($s < 3600)   { return 'من ' . intdiv($s, 60) . ' دقيقة'; }
    if ($s < 86400)  { return 'من ' . intdiv($s, 3600) . ' ساعة'; }
    if ($s < 172800) { return 'امبارح ' . substr($at, 11, 5); }
    return substr($at, 0, 10);
};

$windows = [24 => 'آخر 24 ساعة', 72 => 'آخر 3 أيام', 168 => 'آخر أسبوع', 720 => 'آخر شهر'];
$suspects = array_filter($d['sources'] ?? [], static fn($s) => $s['suspect']);
?>
<div class="page-head">
  <h1><?= icon('alert', 14) ?> المراقبة الأمنية</h1>
  <a class="btn ghost" href="<?= url('audit') ?>"><?= icon('receipt', 14) ?> سجل العمليات</a>
</div>

<?php if (empty($d['ready'])): ?>
  <div class="card" style="padding:20px">
    <p>سجل محاولات الدخول لسه مش متفعّل. شغّل <code>php bin/migrate_v40.php</code>.</p>
  </div>
<?php else: ?>

<?php if (!empty($d['clockSkew'])): ?>
  <div class="card mb" style="padding:11px 14px;border:1px solid #cfd8e3;background:#f4f7fb;font-size:var(--fs-2)">
    <?= icon('clock', 14) ?> <?= e($d['clockSkew']) ?>
  </div>
<?php endif; ?>

<!-- ═══ الحالة دلوقتي ═══ -->
<div class="sec-grid">
  <div class="sec-stat <?= $t['failed'] > 0 ? 'warn' : 'good' ?>">
    <span class="sec-n"><?= (int) $t['failed'] ?></span>
    <span class="sec-l">محاولة دخول فاشلة</span>
    <span class="sec-s"><?= e($windows[$hours] ?? '') ?></span>
  </div>
  <div class="sec-stat">
    <span class="sec-n"><?= (int) $t['ok'] ?></span>
    <span class="sec-l">دخول ناجح</span>
    <span class="sec-s">نفس المدة</span>
  </div>
  <div class="sec-stat <?= count($suspects) ? 'bad' : 'good' ?>">
    <span class="sec-n"><?= count($suspects) ?></span>
    <span class="sec-l">مصدر مشبوه</span>
    <span class="sec-s">بيحاول كتير أو على حسابات كتير</span>
  </div>
  <div class="sec-stat <?= count($d['locked'] ?? []) ? 'warn' : 'good' ?>">
    <span class="sec-n"><?= count($d['locked'] ?? []) ?></span>
    <span class="sec-l">حساب مقفول دلوقتي</span>
    <span class="sec-s">بيتفك لوحده بعد المدة</span>
  </div>
  <div class="sec-stat <?= count($d['weakPins'] ?? []) ? 'warn' : 'good' ?>">
    <span class="sec-n"><?= count($d['weakPins'] ?? []) ?></span>
    <span class="sec-l">رقم سري ضعيف</span>
    <span class="sec-s">مناديب على القاعدة القديمة</span>
  </div>
</div>

<div class="sec-filter">
  <?php foreach ($windows as $h => $lbl): ?>
    <a class="sec-tab <?= $hours === $h ? 'active' : '' ?>" href="<?= url('security?hours=' . $h) ?>"><?= e($lbl) ?></a>
  <?php endforeach; ?>
</div>

<!-- ═══ تنبيهات تحتاج تصرّف ═══ -->
<?php if (count($suspects) || count($d['weakPins'] ?? [])): ?>
<div class="card mb sec-alert">
  <h3><?= icon('alert', 14) ?> محتاج تصرّف منك</h3>
  <ul>
    <?php foreach ($suspects as $s): ?>
      <li><strong><?= e($s['name']) ?></strong> جرّب <?= (int) $s['failed'] ?> مرة
        على <?= (int) $s['accounts'] ?> حساب — آخر مرة <?= e($ago($s['last_at'])) ?>.
        <?php if ((int) $s['ok'] === 0): ?><span class="sec-ok-note">ومنجحش ولا مرة <?= icon('check', 14) ?></span>
        <?php else: ?><strong class="sec-danger">ونجح <?= (int) $s['ok'] ?> مرة — غيّر كلمة السر فورًا.</strong><?php endif; ?>
      </li>
    <?php endforeach; ?>
    <?php if (count($d['weakPins'] ?? [])): ?>
      <li>
        <?= count($d['weakPins']) ?> مندوب لسه برقم سري قديم (4 أرقام):
        <?php foreach ($d['weakPins'] as $w): ?>
          <a class="sec-link" href="<?= url('rep-custody/' . (int) $w['id']) ?>"><?= e($w['label']) ?></a>
        <?php endforeach; ?>
        — افتح كارت كل واحد ودوس «<?= icon('zap', 14) ?> رقم سري جديد».
      </li>
    <?php endif; ?>
  </ul>
</div>
<?php endif; ?>

<div class="sec-cols">
  <!-- ═══ الحسابات اللي اتحاول عليها ═══ -->
  <div class="card">
    <h3 class="sec-h"><?= icon('check-circle', 14) ?> حسابات اتحاول الدخول عليها وفشل</h3>
    <?php if (empty($d['targeted'])): ?>
      <p class="sec-empty">مفيش ولا محاولة فاشلة في المدة دي. تمام <?= icon('check', 14) ?></p>
    <?php else: ?>
      <div class="table-wrap"><table class="data">
        <thead><tr><th>الحساب</th><th>فشل</th><th>نجح</th><th data-no-sort>آخر محاولة</th></tr></thead>
        <tbody>
        <?php foreach ($d['targeted'] as $a): ?>
          <tr<?= $a['kind'] === 'unknown' ? ' class="sec-unknown"' : '' ?>>
            <td><?= e($a['label']) ?><?= $a['kind'] === 'unknown' ? ' <small>(حد بيخمّن أسماء)</small>' : '' ?></td>
            <td><strong><?= (int) $a['failed'] ?></strong></td>
            <td><?= (int) $a['ok'] ? '<span class="sec-danger">' . (int) $a['ok'] . '</span>' : '0' ?></td>
            <td><?= e($ago($a['last_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>

  <!-- ═══ المصادر ═══ -->
  <div class="card">
    <h3 class="sec-h"><?= icon('zap', 14) ?> مصادر المحاولات الفاشلة</h3>
    <p class="sec-note">العناوين متشفّرة في السجل عشان الخصوصية، فبنرقّمها. المهم إنك تشوف
      «مصدر واحد بيحاول كتير» مش «ناس كتير غلطوا مرة».</p>
    <?php if (empty($d['sources'])): ?>
      <p class="sec-empty">مفيش <?= icon('check', 14) ?></p>
    <?php else: ?>
      <div class="table-wrap"><table class="data">
        <thead><tr><th>المصدر</th><th>فشل</th><th>حسابات</th><th data-no-sort>آخر محاولة</th></tr></thead>
        <tbody>
        <?php foreach ($d['sources'] as $s): ?>
          <tr<?= $s['suspect'] ? ' class="sec-suspect"' : '' ?>>
            <td><?= e($s['name']) ?><?= $s['suspect'] ? ' 🚩' : '' ?></td>
            <td><strong><?= (int) $s['failed'] ?></strong></td>
            <td><?= (int) $s['accounts'] ?></td>
            <td><?= e($ago($s['last_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>

  <!-- ═══ المقفول دلوقتي ═══ -->
  <div class="card">
    <h3 class="sec-h"><?= icon('alert', 14) ?> حسابات مقفولة دلوقتي</h3>
    <?php if (empty($d['locked'])): ?>
      <p class="sec-empty">مفيش حساب مقفول <?= icon('check', 14) ?></p>
    <?php else: ?>
      <ul class="sec-list">
        <?php foreach ($d['locked'] as $l): ?>
          <li><strong><?= e($l['label']) ?></strong> — بعد <?= (int) $l['tries'] ?> محاولة غلط،
            بيتفك <?= e(substr($l['until'], 11, 5)) ?> (<?= e(substr($l['until'], 0, 10)) ?>)</li>
        <?php endforeach; ?>
      </ul>
      <p class="sec-note">القفل بيتصاعد: 10 دقايق ← نص ساعة ← ساعتين ← 12 ساعة.
        لو ده موظف نسي كلمة سره، استنى المدة أو غيّرها له من الإعدادات.</p>
    <?php endif; ?>
  </div>

  <!-- ═══ آخر دخولات ═══ -->
  <div class="card">
    <h3 class="sec-h"><?= icon('check', 14) ?> آخر دخولات ناجحة</h3>
    <?php if (empty($d['logins'])): ?>
      <p class="sec-empty">—</p>
    <?php else: ?>
      <ul class="sec-list">
        <?php foreach ($d['logins'] as $g): ?>
          <li><strong><?= e($g['label']) ?></strong> — <?= e($ago($g['at'])) ?></li>
        <?php endforeach; ?>
      </ul>
      <p class="sec-note">لو شفت دخول في وقت غريب (بليل أو إجازة) لحساب مش بتاعك، غيّر كلمة سره فورًا.</p>
    <?php endif; ?>
  </div>

  <!-- ═══ حسابات ساكتة ═══ -->
  <?php if (!empty($d['staleAdmins'])): ?>
  <div class="card">
    <h3 class="sec-h">💤 حسابات مكتب ساكتة</h3>
    <p class="sec-note">حساب محدش بيستخدمه = باب مفتوح بلا حارس. لو مش محتاجه امسحه من الإعدادات.</p>
    <ul class="sec-list">
      <?php foreach ($d['staleAdmins'] as $s): ?>
        <li><strong><?= e($s['label']) ?></strong>
          — <?= $s['never'] ? 'عمره ما دخل' : 'آخر دخول ' . e(substr($s['at'], 0, 10)) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
</div>

<?php endif; ?>

<style>
.sec-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:12px;margin-bottom:14px}
.sec-stat{background:#fff;border:1px solid #e3e8ef;border-radius:var(--radius-2);padding:14px 16px;display:flex;flex-direction:column;gap:2px}
.sec-stat.good{border-inline-start:4px solid #2e9e5b}
.sec-stat.warn{border-inline-start:4px solid #e0a800;background:#fffdf5}
.sec-stat.bad{border-inline-start:4px solid #c0392b;background:#fff6f5}
.sec-n{font-size:var(--fs-7);font-weight:500;line-height:1.1}
.sec-l{font-weight:400;font-size:var(--fs-3)}
.sec-s{font-size:var(--fs-1);color:var(--grey)}
.sec-filter{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.sec-tab{padding:6px 14px;border:1px solid #dde3ec;border-radius:var(--radius-4);font-size:var(--fs-2);text-decoration:none;color:inherit;background:#fff}
.sec-tab.active{background:#1f3b57;color:#fff;border-color:#1f3b57}
.sec-alert{padding:14px 18px;border:1px solid #e5c86e;background:#fff8dc}
.sec-alert h3{margin:0 0 8px;font-size:var(--fs-3);color:#8a3b22}
.sec-alert ul{margin:0;padding-inline-start:20px;line-height:1.9;font-size:var(--fs-2)}
.sec-cols{display:grid;grid-template-columns:repeat(auto-fit,minmax(330px,1fr));gap:14px;align-items:start}
.sec-cols .card{padding:14px 16px}
.sec-h{margin:0 0 10px;font-size:var(--fs-3)}
.sec-note{font-size:var(--fs-1);color:var(--grey);line-height:1.6;margin:8px 0 0}
.sec-empty{font-size:var(--fs-2);color:#2e9e5b;margin:4px 0}
.sec-list{margin:0;padding-inline-start:20px;line-height:1.9;font-size:var(--fs-2)}
.sec-danger{color:#c0392b;font-weight:500}
.sec-ok-note{color:#2e9e5b;font-size:var(--fs-2)}
.sec-suspect{background:#fff6f5}
.sec-unknown{background:#fafafa;color:#666}
.sec-link{display:inline-block;margin-inline-end:6px;text-decoration:underline}
@media print{.sec-filter,.page-head .btn{display:none}}
</style>
