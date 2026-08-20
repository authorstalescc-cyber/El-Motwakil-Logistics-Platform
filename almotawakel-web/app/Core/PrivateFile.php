<?php
namespace App\Core;

/** تخزين وقراءة المرفقات الخاصة خارج public مع دعم مسارات الملفات القديمة. */
class PrivateFile
{
    public const PERMIT_MIME = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'image/heic' => 'heic', 'image/heif' => 'heif', 'image/gif' => 'gif',
        'application/pdf' => 'pdf',
    ];
    public const PHOTO_MIME = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'image/heic' => 'heic', 'image/heif' => 'heif',
    ];

    /** @return string|null المسار المنطقي private/{category}/{random.ext} */
    public static function storeUploaded(array $file, string $category, array $allowedMime, int $maxBytes): ?string
    {
        self::assertCategory($category);
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) { return null; }
        if ($error !== UPLOAD_ERR_OK) { throw new \RuntimeException('تعذّر رفع الملف. اختاره وحاول مرة أخرى.'); }
        if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > $maxBytes) {
            throw new \RuntimeException('حجم الملف غير مسموح.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) { throw new \RuntimeException('ملف الرفع غير صحيح.'); }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
        $ext = $allowedMime[$mime] ?? null;
        if ($ext === null) { throw new \RuntimeException('نوع الملف غير مسموح. استخدم صورة أو PDF صالحًا.'); }

        $dir = self::storageDir($category);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('تعذّر تجهيز مجلد المرفقات.');
        }
        $name = bin2hex(random_bytes(20)) . '.' . $ext;
        if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
            throw new \RuntimeException('تعذّر حفظ الملف. حاول مرة أخرى.');
        }
        @chmod($dir . '/' . $name, 0660);
        return 'private/' . $category . '/' . $name;
    }

    /** @return array{path:string,mime:string,name:string}|null */
    public static function resolve(?string $logical): ?array
    {
        $logical = ltrim(trim((string) $logical), '/');
        if ($logical === '' || str_contains($logical, '..') || str_contains($logical, "\0")) { return null; }

        if (preg_match('#^private/(permits|reps|party_docs|company_safe)/([a-f0-9]{40}\.[a-z0-9]+)$#', $logical, $m)) {
            $path = self::storageDir($m[1]) . '/' . $m[2];
        } elseif (preg_match('#^uploads/(permits|reps)/([A-Za-z0-9._-]+)$#', $logical, $m)) {
            // الملفات القديمة تبقى قابلة للعرض عبر الكنترولر فقط أثناء النقل التدريجي.
            $path = App::basePath('public/uploads/' . $m[1] . '/' . $m[2]);
        } else {
            return null;
        }
        if (!is_file($path) || !is_readable($path)) { return null; }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        return ['path' => $path, 'mime' => $mime, 'name' => basename($path)];
    }

    /**
     * نسخة مستقلّة من مرفق موجود — بترجّع مسارها المنطقي (أو `null` لو مفيش أصل).
     *
     * ⚠️⚠️ **الملف المشترك بين صفّين = قنبلة موقوتة.** لما بند واحد بيتقسم
     *    لكذا بند (إيصالات بأسعار مختلفة — v109.3) وكلهم بيشاوروا على **نفس
     *    الملف**، أول حذف لأي واحد فيهم بيمسح الملف من على القرص
     *    (`deleteStage` بينده `deleteFile`) — والباقي يفضل بيشاور على مرفق
     *    مش موجود **بلا أي خطأ**، ومحدش هيربط العطل بالحذف ده.
     *    عشان كده كل بند بياخد نسخته هو.
     */
    public static function duplicate(?string $logical): ?string
    {
        $src = self::resolve($logical);
        if (!$src) { return null; }

        // بنقرا التصنيف من المسار المنطقي نفسه عشان النسخة تعيش في نفس المجلد.
        if (!preg_match('#^private/(permits|reps|party_docs|company_safe)/[a-f0-9]{40}\.([a-z0-9]+)$#',
                        ltrim((string) $logical, '/'), $m)) {
            return null;   // المرفقات القديمة (`uploads/…`) ما بتتنسخش — بتتساب زي ما هي
        }
        [$category, $ext] = [$m[1], $m[2]];

        $dir = self::storageDir($category);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) { return null; }

        $name = bin2hex(random_bytes(20)) . '.' . $ext;
        if (!@copy($src['path'], $dir . '/' . $name)) { return null; }
        @chmod($dir . '/' . $name, 0660);
        return 'private/' . $category . '/' . $name;
    }

    public static function delete(?string $logical): void
    {
        $file = self::resolve($logical);
        if ($file) { @unlink($file['path']); }
    }

    private static function storageDir(string $category): string
    {
        self::assertCategory($category);
        return App::basePath('storage/private_uploads/' . $category);
    }

    private static function assertCategory(string $category): void
    {
        if (!in_array($category, ['permits', 'reps', 'party_docs', 'company_safe'], true)) {
            throw new \InvalidArgumentException('تصنيف مرفق غير صالح.');
        }
    }
}
