<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\PrivateFile;
use App\Core\RepAuth;

/** بث المرفقات بعد التحقق من جلسة المكتب أو ملكية المندوب للتقديم. */
class FileController extends Controller
{
    public function stage(string $id): void
    {
        $this->requireAuth();
        $row = Database::row('SELECT attachment FROM permit_stages WHERE id=?', [(int) $id]);
        $this->stream($row['attachment'] ?? null);
    }

    public function submission(string $id): void
    {
        $row = Database::row('SELECT rep_id,photo FROM rep_submissions WHERE id=?', [(int) $id]);
        if (!$row) { $this->missing(); }
        if (!Auth::check() && (!RepAuth::check() || RepAuth::repId() !== (int) $row['rep_id'])) {
            http_response_code(403);
            exit('غير مصرح.');
        }
        $this->stream($row['photo'] ?? null);
    }

    public function misc(string $id): void
    {
        $this->requireAuth();
        $row = Database::row('SELECT attachment FROM rep_misc_expenses WHERE id=?', [(int) $id]);
        $this->stream($row['attachment'] ?? null);
    }

    /**
     * الأنواع الوحيدة المسموح بعرضها داخل الصفحة (inline).
     * أي نوع تاني بينزل كملف (attachment) فما بيشتغلش في المتصفح.
     */
    private const INLINE_MIME = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        'image/heic', 'image/heif', 'application/pdf',
    ];

    private function stream(?string $logical): void
    {
        $file = PrivateFile::resolve($logical);
        if (!$file) { $this->missing(); }

        // ⚠️ المرفقات القديمة (public/uploads) اتفحصت بالامتداد بس وقت الرفع،
        //    فملف HTML/SVG متسمّي .png موجود فعلاً ممكن يكون متخزَّن. لو بعتناه
        //    بالنوع اللي finfo بيقوله + inline، المتصفح بينفّذه على نطاق النظام
        //    = XSS مخزَّن بجلسة مكتب. فبنقصر inline على أنواع آمنة معروفة،
        //    وأي حاجة تانية بتنزل كملف بنوع محايد ومستحيل تشتغل.
        $mime = (string) $file['mime'];
        $safe = in_array($mime, self::INLINE_MIME, true);

        header('Content-Type: ' . ($safe ? $mime : 'application/octet-stream'));
        header('Content-Length: ' . filesize($file['path']));
        header(
            'Content-Disposition: ' . ($safe ? 'inline' : 'attachment')
            . '; filename="' . rawurlencode($file['name']) . '"'
        );
        header('X-Content-Type-Options: nosniff');
        // `default-src 'none'` لوحدها بتمنع أي سكربت (حتى inline) لو الملف
        // طلع HTML متنكّر. مش بنستخدم `sandbox` لأنها بتعطّل عارض PDF المدمج،
        // وعرض الفواتير والإيصالات PDF شغل أساسي في النظام.
        header("Content-Security-Policy: default-src 'none'");
        header('Cache-Control: private, no-store, max-age=0');
        readfile($file['path']);
        exit;
    }

    private function missing(): never
    {
        http_response_code(404);
        exit('المرفق غير موجود.');
    }
}
