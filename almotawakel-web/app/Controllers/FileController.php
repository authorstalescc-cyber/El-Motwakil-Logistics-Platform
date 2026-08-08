<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\PrivateFile;
use App\Core\RepAuth;
use App\Models\PartyDoc;
use App\Models\PartyMaster;

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
     * مستند طرف — البطاقة الضريبية / السجل التجاري / … (v56).
     *
     * ⚠️ **`rep_visible` هو الحدّ الوحيد** اللي بيمنع مستندًا يوصل موبايل
     *    المندوب: جلسة المكتب بتشوف كل حاجة، وجلسة المندوب بتشوف المسموح بس.
     *    الفحص بيتأكد إن الشرط ده موجود هنا مش في إخفاء الزرار.
     *
     * `?dl=1` بينزّل الملف باسم عربي مفهوم بدل ما يفتحه في الصفحة.
     */
    /**
     * ورقة من ملفات الإذن (v64).
     *
     * ⚠️ متاحة لجلسة المكتب **ولأي مندوب** — الورقة تخصّ الإذن اللي كل
     *    المناديب شغّالين عليه (قاعدة v43)، ومفيش فيها أي رقم بيخصّ العميل.
     *    الزائر بلا جلسة بيترفض.
     */
    public function permitFile(string $id): void
    {
        $row = \App\Models\PermitFile::find((int) $id);
        if (!$row) { $this->missing(); }
        $office = \App\Core\Auth::check();
        $rep    = class_exists(\App\Core\RepAuth::class) && \App\Core\RepAuth::check();
        if (!$office && !$rep) {
            http_response_code(403);
            exit('غير مصرح.');
        }
        $download = ($_GET['dl'] ?? '') === '1';
        $this->stream($row['file_path'] ?? null, $download,
                      $download ? \App\Models\PermitFile::downloadName($row) : null);
    }

    /**
     * إيصال حركة خزنة الشركة (v67).
     *
     * ⚠️⚠️ **جلسة المكتب بس — والمندوب مرفوض عن قصد.** الملف ده إيصال إيجار أو
     *    مرتبات أو فاتورة مورّد، وكله بيخصّ حسابات الشركة. `Auth::check()`
     *    بتدوّر على `uid` وجلسة المندوب مالهاش (v35)، فالرفض هو الافتراضي.
     *    **ما تضيفش `RepAuth::check()` هنا** زي `permitFile` — دي حالة مختلفة.
     */
    public function companySafeFile(string $id): void
    {
        $this->requireAuth();
        $row = \App\Models\CompanySafe::find((int) $id);
        if (!$row || empty($row['attachment'])) { $this->missing(); }
        $download = ($_GET['dl'] ?? '') === '1';
        // ⚠️ الامتداد لازم يفضل في اسم التنزيل وإلا ويندوز مايعرفش يفتح الملف.
        $ext = pathinfo((string) $row['attachment'], PATHINFO_EXTENSION);
        $this->stream((string) $row['attachment'], $download,
            $download ? 'ايصال-خزنة-الشركة-' . (int) $id . ($ext !== '' ? '.' . $ext : '') : null);
    }

    public function partyDoc(string $id): void
    {
        $row = PartyDoc::find((int) $id);
        if (!$row) { $this->missing(); }
        if (!$this->mayReadDoc($row)) {
            http_response_code(403);
            exit('غير مصرح.');
        }
        $download = ($_GET['dl'] ?? '') === '1';
        $this->stream($row['file_path'] ?? null, $download, $download ? PartyDoc::downloadName($row) : null);
    }

    /**
     * صفحة طباعة المستند — الغرض إن المندوب يطبعه من الموبايل بضغطة.
     *
     * الـPDF بيتفتح في عارض المتصفح المدمج (وهو بيطبع أحسن من أي صفحة نعملها)،
     * والصورة بتتحط في ورقة A4 وتتطبع لوحدها. **الصفحة بلا أي مبلغ** التزامًا
     * بقاعدة v35 (ممنوع أي رقم بيخصّ العميل يوصل شاشة المندوب).
     */
    public function partyDocPrint(string $id): void
    {
        $row = PartyDoc::find((int) $id);
        if (!$row) { $this->missing(); }
        if (!$this->mayReadDoc($row)) {
            http_response_code(403);
            exit('غير مصرح.');
        }
        // ⚠️ الـPDF بيتبعت **inline من هنا مباشرةً** مش redirect: عارض المتصفح
        //    المدمج بيطبع أحسن من أي صفحة نعملها، و`redirect()` هنا مش نهاية
        //    إجراء فمكانش هيبقى معاها رسالة تتقال للمستخدم (قاعدة verify_ux).
        if (PartyDoc::isPdf($row)) {
            $this->stream($row['file_path'] ?? null);
        }
        $this->render('documents/print', [
            'title' => $row['title'],
            'doc'   => $row,
            'party' => PartyMaster::find((string) $row['party_type'], (int) $row['party_id']),
        ], false);
    }

    /** جلسة المكتب تقرأ كل المستندات، وجلسة المندوب المسموح له بس. */
    private function mayReadDoc(array $row): bool
    {
        if (Auth::check()) { return true; }
        return RepAuth::check() && (int) ($row['rep_visible'] ?? 0) === 1;
    }

    /**
     * الأنواع الوحيدة المسموح بعرضها داخل الصفحة (inline).
     * أي نوع تاني بينزل كملف (attachment) فما بيشتغلش في المتصفح.
     */
    private const INLINE_MIME = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        'image/heic', 'image/heif', 'application/pdf',
    ];

    private function stream(?string $logical, bool $forceDownload = false, ?string $niceName = null): void
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
        $inline = $safe && !$forceDownload;

        // اسم التنزيل بالعربي لازم `filename*` (RFC 5987) — `filename` لوحدها
        // بتتقرا Latin-1 فالاسم العربي بيطلع رموزًا مكسورة على ويندوز.
        $name = $niceName !== null && trim($niceName) !== '' ? trim($niceName) : $file['name'];

        header('Content-Type: ' . ($safe ? $mime : 'application/octet-stream'));
        header('Content-Length: ' . filesize($file['path']));
        header(
            'Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
            . '; filename="' . rawurlencode($file['name']) . '"'
            . "; filename*=UTF-8''" . rawurlencode($name)
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
