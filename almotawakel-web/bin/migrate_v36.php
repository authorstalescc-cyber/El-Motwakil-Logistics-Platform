<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * ترحيل v36 — حماية إدخال المندوب من التكرار + قفل البند عند بدء المراجعة.
 *
 * آمن لإعادة التشغيل (idempotent).
 * التشغيل: php bin/migrate_v36.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

function v36ColumnExists(string $table, string $column): bool
{
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
        [$table, $column]
    );
}

function v36IndexExists(string $table, string $index): bool
{
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema=DATABASE() AND table_name=? AND index_name=?',
        [$table, $index]
    );
}

echo "ترحيل v36 — حماية إدخال المناديب…\n";

if (!v36ColumnExists('rep_submissions', 'client_token')) {
    Database::exec(
        "ALTER TABLE rep_submissions
         ADD COLUMN client_token VARCHAR(64) NULL
             COMMENT 'رمز ثابت لمحاولة الإرسال — يمنع التكرار عند إعادة المحاولة'
             AFTER rep_id"
    );
    echo "  + client_token\n";
} else {
    echo "  = client_token موجود\n";
}

if (!v36IndexExists('rep_submissions', 'uq_rep_submission_token')) {
    Database::exec(
        'ALTER TABLE rep_submissions
         ADD UNIQUE KEY uq_rep_submission_token (rep_id, client_token)'
    );
    echo "  + uq_rep_submission_token\n";
} else {
    echo "  = uq_rep_submission_token موجود\n";
}

if (!v36ColumnExists('rep_submissions', 'review_started_at')) {
    Database::exec(
        "ALTER TABLE rep_submissions
         ADD COLUMN review_started_at DATETIME NULL
             COMMENT 'من اللحظة دي المندوب ما يقدرش يعدّل أو يمسح'
             AFTER review_note"
    );
    echo "  + review_started_at\n";
} else {
    echo "  = review_started_at موجود\n";
}

if (!v36ColumnExists('rep_submissions', 'review_started_by')) {
    Database::exec(
        "ALTER TABLE rep_submissions
         ADD COLUMN review_started_by VARCHAR(120) NULL
             COMMENT 'موظف المكتب الذي بدأ المراجعة'
             AFTER review_started_at"
    );
    echo "  + review_started_by\n";
} else {
    echo "  = review_started_by موجود\n";
}

if (!v36ColumnExists('rep_submissions', 'updated_at')) {
    Database::exec(
        "ALTER TABLE rep_submissions
         ADD COLUMN updated_at DATETIME NULL
             COMMENT 'آخر تعديل من المندوب قبل المراجعة'
             AFTER created_at"
    );
    echo "  + updated_at\n";
} else {
    echo "  = updated_at موجود\n";
}

echo "اكتمل ترحيل v36.\n";
