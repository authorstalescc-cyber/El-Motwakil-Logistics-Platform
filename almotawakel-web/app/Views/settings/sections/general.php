<?php
/**
 * بيانات الشركة والإعدادات العامة (docs/19 · المرحلة ٣).
 *
 * ⚠️ جزء بيتحمّل من `settings/index.php` — **صفر `<style>` محلي**،
 *    الكلاسات كلها في `app.css` تحت «مركز التحكّم».
 *
 * @var array $general
 */
?>
  <div class="card set-pad narrow">
    <div class="section-head"><?= icon('home', 17) ?> بيانات الشركة والإعدادات العامة</div>
    <form method="post" action="<?= url('settings/general') ?>">
      <?= csrfField() ?>
      <div class="st-grid">
        <div class="field"><label>اسم الشركة</label>
          <input type="text" name="company_name" value="<?= e($general['company_name']) ?>"></div>
        <div class="field"><label>العملة</label>
          <input type="text" name="currency" value="<?= e($general['currency']) ?>"></div>
        <div class="field"><label><?= icon('send', 14) ?> تليفون الشركة (يظهر في الفاتورة)</label>
          <input type="text" name="company_phone" value="<?= e($general['company_phone']) ?>" placeholder="مثال: 03-000000"></div>
        <div class="field"><label><?= icon('send', 14) ?> البريد الإلكتروني</label>
          <input type="text" name="company_email" value="<?= e($general['company_email']) ?>" placeholder="اختياري"></div>
        <div class="field field-full"><label><?= icon('map-pin', 14) ?> عنوان الشركة (يظهر في الفاتورة)</label>
          <input type="text" name="company_address" value="<?= e($general['company_address']) ?>" placeholder="العنوان بالكامل"></div>
        <div class="field"><label><?= icon('receipt', 14) ?> البطاقة الضريبية / السجل التجاري</label>
          <input type="text" name="company_tax_id" value="<?= e($general['company_tax_id']) ?>" placeholder="اختياري"></div>
        <?php /* الاتنين دول كانوا **بيتطبعوا في تقرير السيارات وكشف خزنة الشركة
                 وقيمتهم مزروعة في الـview نفسه** — أول مرة يبقى ليهم خانة (المرحلة ١). */ ?>
        <?php partial('setting_field', ['key' => 'company_tagline', 'value' => \App\Core\Settings::get('company_tagline')]); ?>
        <?php partial('setting_field', ['key' => 'company_activity', 'value' => \App\Core\Settings::get('company_activity')]); ?>
        <div class="field"><label>نسبة القيمة المضافة (0.14 أو 14)</label>
          <input type="number" step="0.01" name="vat_rate" value="<?= e($general['vat_rate']) ?>"></div>
        <div class="field"><label>سنة التقارير</label>
          <input type="number" name="report_year" value="<?= e($general['report_year']) ?>"></div>
        <div class="field"><label>باقي عهدة سابقة (رصيد افتتاحي)</label>
          <input data-money type="number" step="0.01" name="opening_balance" value="<?= e($general['opening_balance']) ?>"></div>
        <div class="field field-full"><label>تاريخ بداية احتساب الرصيد الافتتاحي</label>
          <input type="date" name="opening_balance_date" value="<?= e($general['opening_balance_date']) ?>"></div>
      </div>
      <div class="mt-4"><button class="btn gold" type="submit"><?= icon('download', 14) ?> حفظ الإعدادات العامة</button></div>
    </form>
  </div>
