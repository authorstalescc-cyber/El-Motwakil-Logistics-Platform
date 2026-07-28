<?php
/**
 * مستندات أطراف الإذن على موبايل المندوب (v56).
 *
 * ⚠️ **قراءة فقط بالكامل** — ولا فورم واحد، **ولا رقم مالي واحد** (قاعدة v35:
 *    ممنوع أي مبلغ بيخصّ العميل يوصل شاشة المندوب). الحدّ الحقيقي على الملف
 *    نفسه في `FileController::partyDoc` (شرط `rep_visible`) مش في إخفاء زرار.
 *
 * المتغيّرات: $permit $groups
 */
use App\Models\PartyDoc;

$groups = $groups ?? [];
$total  = 0;
foreach ($groups as $g) { $total += count($g['docs'] ?? []); }
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0F151E">
<title>مستندات <?= e($permit['no']) ?></title>
<link rel="icon" href="<?= url('assets/img/logo.png') ?>">
<link rel="stylesheet" href="<?= url('assets/css/m.css') ?>?v=<?= @filemtime(\App\Core\App::basePath('public/assets/css/m.css')) ?>">
<?php require __DIR__ . "/_pwa.php"; ?>
</head>
<body class="hm-body hf-body">

<div class="hf-top">
  <header class="hf-bar">
    <?php $backTo = 'm/permit/' . (int) $permit['id']; require __DIR__ . '/_back.php'; ?>
    <span class="hf-title">مستندات الإذن</span>
  </header>

  <div class="hf-context">
    <span class="hf-kind <?= ($permit['type'] ?? 'sader') === 'warid' ? 'is-warid' : 'is-sader' ?>"><?= e($permit['type_label']) ?></span>
    <b><?= e($permit['company'] ?: $permit['client'] ?: 'بدون شركة') ?></b>
    <span class="hf-no"><?= e($permit['no']) ?></span>
  </div>
</div>

<main class="hf-sheet">
  <span class="hm-grab" aria-hidden="true"></span>

  <?php if ($total === 0): ?>
    <div class="hm-empty">
      <span class="ic"><?= icon('folder', 14) ?></span>
      <b>مفيش مستندات للإذن ده</b>
      <p>المكتب هو اللي بيرفع البطاقة الضريبية والسجل التجاري. كلّمه لو محتاجهم.</p>
    </div>
  <?php else: ?>
    <p class="md-hint">اضغط «تحميل» عشان الورقة تنزل على موبايلك وتفضل معاك من غير نت، أو «طباعة» لو هتطبعها على طول.</p>

    <?php foreach ($groups as $g): ?>
      <?php if (empty($g['docs'])) { continue; } ?>
      <h3 class="hm-group">
        <?= e((string) $g['name']) ?> <span><?= e((string) $g['type_label']) ?></span>
      </h3>

      <?php foreach ($g['docs'] as $d): ?>
        <?php
          $docId = (int) $d['id'];
          $exp   = PartyDoc::expiryState($d);
          $isPdf = PartyDoc::isPdf($d);
        ?>
        <article class="md-card<?= $exp['state'] === 'expired' ? ' is-expired' : '' ?>">
          <div class="md-head">
            <span class="md-ic"><?= icon(PartyDoc::kindIcon($d['doc_kind'] ?? null), 18) ?></span>
            <div class="md-title">
              <b><?= e((string) $d['title']) ?></b>
              <span><?= e(PartyDoc::kindLabel($d['doc_kind'] ?? null)) ?> · <?= $isPdf ? 'PDF' : 'صورة' ?></span>
            </div>
          </div>

          <?php if (!empty($d['doc_no']) || $exp['state'] !== 'none'): ?>
            <div class="md-meta">
              <?php if (!empty($d['doc_no'])): ?>
                <span>رقم: <bdi dir="ltr"><?= e((string) $d['doc_no']) ?></bdi></span>
              <?php endif; ?>
              <?php if ($exp['state'] === 'expired'): ?>
                <span class="md-flag bad"><?= icon('alert', 13) ?> منتهي في <?= dfmt($d['expires_at']) ?></span>
              <?php elseif ($exp['state'] === 'soon'): ?>
                <span class="md-flag warn"><?= icon('clock', 13) ?> <?= e($exp['label']) ?></span>
              <?php elseif ($exp['state'] === 'ok'): ?>
                <span class="md-flag ok"><?= icon('check', 13) ?> سارٍ حتى <?= dfmt($d['expires_at']) ?></span>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <div class="md-acts">
            <a class="md-btn" href="<?= url('attachments/party-doc/' . $docId) ?>" target="_blank" rel="noopener">
              <?= icon('external', 15) ?> افتح</a>
            <a class="md-btn is-main" href="<?= url('attachments/party-doc/' . $docId . '?dl=1') ?>">
              <?= icon('download', 15) ?> تحميل</a>
            <a class="md-btn" href="<?= url('attachments/party-doc/' . $docId . '/print') ?>" target="_blank" rel="noopener">
              <?= icon('receipt', 15) ?> طباعة</a>
          </div>
        </article>
      <?php endforeach; ?>
    <?php endforeach; ?>
  <?php endif; ?>
</main>
</body>
</html>
