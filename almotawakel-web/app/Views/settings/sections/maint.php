<?php
/**
 * الصيانة وحالة النظام وسجل التغييرات (docs/19 · المرحلة ٣).
 *
 * ⚠️ جزء بيتحمّل من `settings/index.php` — **صفر `<style>` محلي**،
 *    الكلاسات كلها في `app.css` تحت «مركز التحكّم».
 *
 * @var array $stateRows @var array $recentChanges
 */
?>
  <div class="st-grid">
    <?php if ($canManageSecrets): ?>
    <div class="card set-pad">
      <div class="section-head"><?= icon('download', 17) ?> النسخ الاحتياطي</div>
      <form method="post" action="<?= url('backup') ?>" class="no-confirm">
        <?= csrfField() ?>
        <button class="btn" type="submit">⬇️ تنزيل نسخة احتياطية الآن</button>
      </form>
    </div>
    <?php endif; ?>

    <?php /* ══ قفل بوابة المناديب للصيانة (v68) ═══════════════════════════
             ⚠️ **مش زرار بلا رجعة**: القفل بياخد مدة متوقَّعة وبيفتح لوحده
                لما تعدّي، والشريط الأحمر فوق كل شاشة بيفضل ظاهر لحد ما يتفتح
                — عشان محدّش ينسى المناديب واقفين في الميناء. */ ?>
    <div class="card set-pad" id="rep-maint">
      <div class="section-head"><?= icon('wrench', 17) ?> بوابة المناديب</div>
      <?php if ($repMaint['on']): ?>
        <p class="set-p">
          البوابة <b>مقفولة دلوقتي</b> — المندوب بيشوف شاشة «النظام بيتظبّط».
          <?php if ($repMaintBackAt !== ''): ?>
            متوقع تفتح لوحدها الساعة <b><bdi dir="ltr"><?= e($repMaintBackAt) ?></bdi></b>.
          <?php else: ?>
            مفيش وقت محدَّد — هتفضل مقفولة لحد ما تفتحها.
          <?php endif; ?>
        </p>
        <?php if (($repMaint['message'] ?? '') !== ''): ?>
          <p class="set-p">الرسالة اللي شايفها: «<?= e($repMaint['message']) ?>»</p>
        <?php endif; ?>
        <form method="post" action="<?= url('settings/rep-maintenance') ?>" class="no-confirm">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="off">
          <button class="btn gold" type="submit"><?= icon('check-circle', 14) ?> افتح البوابة وابعتلهم إشعار</button>
        </form>
      <?php else: ?>
        <p class="set-p">
          بتقفل شاشة المندوب وانت بتظبّط حاجة، وبتبعتلهم إشعار على طول —
          وإشعار تاني أول ما ترجع. <b>مسوّداتهم ما بتضيعش.</b>
        </p>
        <form method="post" action="<?= url('settings/rep-maintenance') ?>" class="no-confirm">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="on">
          <div class="field">
            <label for="rmMsg">رسالة للمناديب (اختيارية)</label>
            <?php /* ⚠️ بلا `placeholder` تعليمي (قانون التصميم ①) — العنوان
                     بيقول المطلوب، والمثال جوّه الخانة بيتقري كأنه قيمة. */ ?>
            <input type="text" id="rmMsg" name="message" maxlength="200">
          </div>
          <div class="field">
            <label for="rmMin">المدة المتوقّعة بالدقايق</label>
            <input type="number" id="rmMin" name="minutes" inputmode="numeric"
                   min="<?= (int) \App\Models\RepMaintenance::MIN_MINUTES ?>"
                   max="<?= (int) \App\Models\RepMaintenance::MAX_MINUTES ?>" value="30">
            <small class="dim">سيبها فاضية يعني مفتوح لحد ما تقفله بإيدك.</small>
          </div>
          <button class="btn" type="submit"><?= icon('lock', 14) ?> اقفل بوابة المناديب</button>
        </form>
      <?php endif; ?>
    </div>

    <?php /* ══ حالة النظام (docs/19 · المرحلة ١) ═══════════════════════════
             ⚠️ **عرض بس بلا خانات إدخال**: المفاتيح دي بيكتبها عامل خلفي
                (`bin/backup_cron.sh` · `bin/push_worker.php`) و`Settings::set()`
                بترفضها — فخانة قابلة للكتابة كانت هتوعد بحاجة الخادم رافضها.
                وقبل كده كانت **بتتقرا وما بتتعرضش في أي شاشة** أصلاً. */ ?>
    <?php if (!empty($stateRows)): ?>
    <div class="card set-pad">
      <?php /* ⚠️ `icon('اسم-غلط')` ما بترميش خطأ — بترسم دايرة فاضية.
               الأسماء دي متأكَّد إنها في كتالوج `helpers.php`. */ ?>
      <div class="section-head"><?= icon('monitor', 17) ?> حالة النظام</div>
      <div class="table-wrap"><table class="data">
        <thead><tr><th class="g-identity">البند</th><th>القيمة</th><th class="g-meta">مين بيكتبه</th></tr></thead>
        <tbody>
          <?php foreach ($stateRows as $st): ?>
            <tr>
              <td><?= e($st['label']) ?></td>
              <td><?= $st['value'] === '' ? '<span class="dim">— لسه —</span>' : '<bdi>' . e($st['value']) . '</bdi>' ?></td>
              <td class="dim"><?= e($st['writer']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php endif; ?>

    <?php /* ══ آخر تغييرات الإعدادات — «مين غيّر إيه وإمتى» (v70) ═══════════ */ ?>
    <?php if (!empty($recentChanges)): ?>
    <div class="card set-pad">
      <div class="section-head"><?= icon('clipboard', 17) ?> آخر تغييرات الإعدادات</div>
      <div class="table-wrap"><table class="data">
        <thead><tr>
          <th class="g-identity">الإعداد</th><th>من</th><th>لـ</th>
          <th class="g-meta">مين</th><th class="g-meta">إمتى</th><th></th>
        </tr></thead>
        <tbody>
          <?php foreach ($recentChanges as $ch): ?>
            <tr>
              <td><?= e($ch['label']) ?></td>
              <td class="dim"><bdi><?= e(mb_substr((string) $ch['old_value'], 0, 40)) ?></bdi></td>
              <td><b><bdi><?= e(mb_substr((string) $ch['new_value'], 0, 40)) ?></bdi></b></td>
              <td class="dim"><?= e((string) ($ch['changed_by'] ?? '—')) ?></td>
              <td class="dim nowrap"><?= e(dtfmt($ch['created_at'])) ?></td>
              <?php /* رجوع بضغطة (v79) — بيمرّ على `Settings::set()` فالتحقّق
                       والتسجيل بيتطبّقوا؛ الرجوع تغيير مش استثناء. */ ?>
              <td class="end">
                <form method="post" action="<?= url('settings/revert/' . (int) $ch['id']) ?>"
                      data-confirm="ترجّع «<?= e($ch['label']) ?>» لقيمتها السابقة؟">
                  <?= csrfField() ?>
                  <button type="submit" class="btn ghost sm" title="رجوع"><?= icon('edit', 14) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php endif; ?>

    <div class="card set-pad">
      <div class="section-head"><?= icon('file-text', 17) ?> توحيد المسميات المكررة</div>
      <p class="set-p">
        الزرار القديم كان بيدمج <b>من غير ما يوريك هيدمج إيه في إيه</b> وبيختار الاسم
        المعتمد لوحده. بقى فيه شاشة بتعرض كل مجموعة بعدد استخدام كل تهجئة
        <b>وانت اللي تختار</b>.
      </p>
      <a class="btn gold" href="<?= url('quality') ?>"><?= icon('clipboard', 14) ?> افتح شاشة تنظيف البيانات</a>
    </div>
  </div>
