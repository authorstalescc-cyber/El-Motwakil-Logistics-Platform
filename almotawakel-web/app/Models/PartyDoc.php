<?php
namespace App\Models;

use App\Core\Database;
use App\Core\PrivateFile;
use RuntimeException;

/**
 * مستندات الأطراف — البطاقة الضريبية / السجل التجاري / … (v56).
 *
 * المكتب بيرفع أوراق الشركة (أو الوكيل أو صاحب البيان) مرّة واحدة في
 * بروفايله، والنظام بيوصّلها لكل إذن يخصّ الطرف ده وللمندوب على موبايله.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * ⚠️ وحدة وصفية معزولة — **مفيش أي رقم مالي ولا لمسة لخزنة/تقفيل/عهدة**.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ **الربط بالإذن نصّي بلا FK (تحذير ٣).** المستند على سجل الطرف، والإذن
 *    بيوصل له بـ`PartyMaster::resolve()` — فالتهجئات المختلفة بتلمّ على نفس
 *    السجل تلقائيًّا، وحذف طرف ما بيكسرش أي إذن.
 *
 * ⚠️ **`available()` بترجّع false قبل الترحيل** فالنظام يفضل بسلوكه القديم
 *    بدل ما يكسر — نفس نمط `RepCustody::available()`.
 */
class PartyDoc
{
    /** أنواع المستندات المعروفة (المصدر الوحيد للاسم والأيقونة). */
    public const KINDS = [
        'tax_card'       => ['label' => 'البطاقة الضريبية',        'icon' => 'receipt'],
        'commercial_reg' => ['label' => 'السجل التجاري',           'icon' => 'file-text'],
        'vat_cert'       => ['label' => 'شهادة تسجيل ض.ق.م',       'icon' => 'receipt'],
        'import_card'    => ['label' => 'البطاقة الاستيرادية',     'icon' => 'package'],
        'customs_card'   => ['label' => 'البطاقة الجمركية',        'icon' => 'anchor'],
        'power_attorney' => ['label' => 'توكيل',                    'icon' => 'file-text'],
        'id_card'        => ['label' => 'بطاقة الرقم القومي',      'icon' => 'user'],
        'contract'       => ['label' => 'عقد',                      'icon' => 'briefcase'],
        'other'          => ['label' => 'مستند آخر',               'icon' => 'folder'],
    ];

    /** أنواع الأطراف اللي بيتعلّق عليها مستند (نفس مفاتيح PartyMaster). */
    public const PARTY_TYPES = ['company', 'agent', 'owner'];

    public const MAX_BYTES = 12 * 1024 * 1024;

    /** ⚠️ الترحيل ممكن ما يكونش اتشغّل — النظام لازم يفضل شغّال بلا الوحدة. */
    public static function available(): bool
    {
        static $ready = null;
        if ($ready === null) {
            $ready = (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'party_documents'"
            );
        }
        return $ready;
    }

    public static function kindLabel(?string $kind): string
    {
        return self::KINDS[(string) $kind]['label'] ?? self::KINDS['other']['label'];
    }

    public static function kindIcon(?string $kind): string
    {
        return self::KINDS[(string) $kind]['icon'] ?? 'folder';
    }

    public static function partyLabel(string $type): string
    {
        return PartyMaster::TYPES[$type]['label'] ?? $type;
    }

    /**
     * حالة صلاحية المستند — شارة عرض فقط، **ما بتمنعش أي حاجة**.
     * @return array{state:string,label:string,days:?int}
     */
    public static function expiryState(array $row): array
    {
        $exp = trim((string) ($row['expires_at'] ?? ''));
        if ($exp === '' || $exp === '0000-00-00') {
            return ['state' => 'none', 'label' => '', 'days' => null];
        }
        $days = (int) floor((strtotime($exp) - strtotime(date('Y-m-d'))) / 86400);
        if ($days < 0)  { return ['state' => 'expired', 'label' => 'منتهي', 'days' => $days]; }
        if ($days <= 30) { return ['state' => 'soon', 'label' => 'بيخلص خلال ' . $days . ' يوم', 'days' => $days]; }
        return ['state' => 'ok', 'label' => 'سارٍ', 'days' => $days];
    }

    public static function find(int $id): ?array
    {
        if (!self::available()) { return null; }
        return Database::row('SELECT * FROM party_documents WHERE id = ?', [$id]);
    }

    /**
     * مستندات طرف واحد.
     * $repOnly = true → المستندات المسموح للمندوب يشوفها بس.
     */
    public static function forParty(string $type, int $partyId, bool $repOnly = false): array
    {
        if (!self::available() || $partyId <= 0 || !in_array($type, self::PARTY_TYPES, true)) { return []; }
        return Database::all(
            'SELECT * FROM party_documents
              WHERE party_type = ? AND party_id = ?'
            . ($repOnly ? ' AND rep_visible = 1' : '') .
            ' ORDER BY doc_kind = \'other\', doc_kind, id DESC',
            [$type, $partyId]
        );
    }

    /** عدّاد سريع لكل طرف (لشارة في قائمة البيانات الأساسية). */
    public static function countsByParty(string $type): array
    {
        if (!self::available()) { return []; }
        $rows = Database::all(
            'SELECT party_id, COUNT(*) n FROM party_documents WHERE party_type = ? GROUP BY party_id',
            [$type]
        );
        $out = [];
        foreach ($rows as $r) { $out[(int) $r['party_id']] = (int) $r['n']; }
        return $out;
    }

    /**
     * مستندات إذن واحد — الشركة والوكيل مع بعض، كل مجموعة بطرفها.
     *
     * ⚠️ الحل بيمرّ على `PartyMaster::resolve()` مش على مطابقة نصّية مباشرة،
     *    عشان «المصرية الألمانية» و«المصريه الالمانيه» يلمّوا على نفس السجل
     *    (تحذير ٣ + درس v38).
     *
     * @return array<int,array{type:string,type_label:string,name:string,party_id:int,docs:array}>
     */
    public static function forPermit(array $permit, bool $repOnly = false): array
    {
        if (!self::available()) { return []; }
        $groups = [];
        $seen   = [];
        $wanted = [
            ['company', trim((string) ($permit['company'] ?? ''))],
            ['agent',   trim((string) ($permit['client']  ?? ''))],
        ];
        foreach ($wanted as [$type, $name]) {
            if ($name === '') { continue; }
            try {
                $party = PartyMaster::resolve($type, $name);
            } catch (\Throwable $e) {
                $party = null;
            }
            if (!$party) { continue; }
            $key = $type . ':' . (int) $party['id'];
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;

            $docs = self::forParty($type, (int) $party['id'], $repOnly);
            if (!$docs) { continue; }
            $groups[] = [
                'type'       => $type,
                'type_label' => self::partyLabel($type),
                'name'       => (string) $party['name'],
                'party_id'   => (int) $party['id'],
                'docs'       => $docs,
            ];
        }
        return $groups;
    }

    /**
     * مجموعة مفاتيح التطبيع للأطراف اللي عندها مستندات — **استعلامان بس**.
     *
     * ⚠️ لازمة عشان قائمة أذونات المندوب: `forPermit()` بتعمل `resolve()` لكل
     *    إذن، وده ٦٠٠ استعلام على شاشة فيها ٣٠٠ إذن. هنا بنجيب المفاتيح مرّة
     *    واحدة وبنطابق في PHP بـ**نفس دالة التطبيع** (`PartyMaster::normalizeName`)
     *    فمستحيل النتيجة تخالف `resolve()`.
     *
     * @return array<string,array<string,bool>> ['company'=>['<key>'=>true,…], 'agent'=>[…]]
     */
    public static function keySet(bool $repOnly = false): array
    {
        if (!self::available()) { return []; }
        $where = $repOnly ? ' AND d.rep_visible = 1' : '';

        // أسماء الأطراف نفسها + كل تهجئاتها المسجَّلة
        $rows = Database::all(
            "SELECT d.party_type AS t, COALESCE(c.name, g.name, o.name) AS name
               FROM party_documents d
               LEFT JOIN companies c           ON d.party_type = 'company' AND c.id = d.party_id
               LEFT JOIN agents g              ON d.party_type = 'agent'   AND g.id = d.party_id
               LEFT JOIN declaration_owners o  ON d.party_type = 'owner'   AND o.id = d.party_id
              WHERE 1 = 1" . $where
        );
        $aliases = Database::all(
            "SELECT DISTINCT d.party_type AS t, a.normalized_alias AS name
               FROM party_documents d
               JOIN party_aliases a ON a.party_type = d.party_type AND a.party_id = d.party_id
              WHERE 1 = 1" . $where
        );

        $out = [];
        foreach ($rows as $r) {
            $key = PartyMaster::normalizeName((string) ($r['name'] ?? ''));
            if ($key !== '') { $out[(string) $r['t']][$key] = true; }
        }
        foreach ($aliases as $r) {
            // الـalias متخزَّن مطبَّعًا أصلاً، بس بنعدّيه تاني تحسّبًا لأي صف قديم.
            $key = PartyMaster::normalizeName((string) ($r['name'] ?? ''));
            if ($key !== '') { $out[(string) $r['t']][$key] = true; }
        }
        return $out;
    }

    /** هل الإذن ده ليه مستندات؟ (بيقرا من `keySet` — بلا أي استعلام إضافي) */
    public static function permitHasDocs(array $keySet, ?string $company, ?string $client): bool
    {
        $pairs = [['company', $company], ['agent', $client]];
        foreach ($pairs as [$type, $name]) {
            $key = PartyMaster::normalizeName(trim((string) $name));
            if ($key !== '' && !empty($keySet[$type][$key])) { return true; }
        }
        return false;
    }

    /**
     * رفع مستند جديد. بيرمي RuntimeException برسالة عربية عند أي رفض.
     * الفورم مش مصدر ثقة: النوع والطرف بيتفحصوا هنا قبل أي كتابة.
     */
    public static function create(string $type, int $partyId, array $data, array $file, ?string $by = null): int
    {
        if (!self::available()) {
            throw new RuntimeException('وحدة المستندات مش متفعّلة — شغّل php bin/migrate_v56.php.');
        }
        if (!in_array($type, self::PARTY_TYPES, true)) {
            throw new RuntimeException('نوع الطرف مش صحيح.');
        }
        if (!PartyMaster::find($type, $partyId)) {
            throw new RuntimeException('الطرف ده مش موجود.');
        }

        $kind = (string) ($data['doc_kind'] ?? 'other');
        if (!isset(self::KINDS[$kind])) { $kind = 'other'; }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') { $title = self::kindLabel($kind); }
        if (mb_strlen($title) > 180) { $title = mb_substr($title, 0, 180); }

        $path = PrivateFile::storeUploaded($file, 'party_docs', PrivateFile::PERMIT_MIME, self::MAX_BYTES);
        if ($path === null) {
            throw new RuntimeException('اختار الملف الأول (صورة أو PDF).');
        }

        try {
            Database::exec(
                'INSERT INTO party_documents
                    (party_type, party_id, doc_kind, title, doc_no, issue_date, expires_at,
                     file_path, file_ext, rep_visible, note, uploaded_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $type, $partyId, $kind, $title,
                    self::nullable($data['doc_no'] ?? null, 120),
                    self::date($data['issue_date'] ?? null),
                    self::date($data['expires_at'] ?? null),
                    $path,
                    strtolower((string) pathinfo($path, PATHINFO_EXTENSION)),
                    empty($data['rep_visible']) ? 0 : 1,
                    self::nullable($data['note'] ?? null, 255),
                    $by !== null ? mb_substr($by, 0, 120) : null,
                ]
            );
        } catch (\Throwable $e) {
            // ما نسيبش ملفًا يتيمًا على القرص لو الصف ما اتكتبش.
            PrivateFile::delete($path);
            throw $e;
        }
        return Database::lastId();
    }

    /** تعديل البيانات الوصفية فقط — الملف نفسه ما بيتغيّرش (ارفع مستندًا جديدًا). */
    public static function update(int $id, array $data): void
    {
        $row = self::find($id);
        if (!$row) { throw new RuntimeException('المستند ده مش موجود.'); }

        $kind = (string) ($data['doc_kind'] ?? $row['doc_kind']);
        if (!isset(self::KINDS[$kind])) { $kind = 'other'; }
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') { $title = self::kindLabel($kind); }

        Database::exec(
            'UPDATE party_documents
                SET doc_kind = ?, title = ?, doc_no = ?, issue_date = ?, expires_at = ?,
                    rep_visible = ?, note = ?
              WHERE id = ?',
            [
                $kind, mb_substr($title, 0, 180),
                self::nullable($data['doc_no'] ?? null, 120),
                self::date($data['issue_date'] ?? null),
                self::date($data['expires_at'] ?? null),
                empty($data['rep_visible']) ? 0 : 1,
                self::nullable($data['note'] ?? null, 255),
                $id,
            ]
        );
    }

    /** حذف المستند وملفه معًا. */
    public static function delete(int $id): ?array
    {
        $row = self::find($id);
        if (!$row) { return null; }
        Database::exec('DELETE FROM party_documents WHERE id = ?', [$id]);
        PrivateFile::delete($row['file_path'] ?? null);
        return $row;
    }

    /** حجم الملف بالبايت (للعرض) — بيرجّع 0 لو الملف اتشال من القرص. */
    public static function fileSize(array $row): int
    {
        $f = PrivateFile::resolve($row['file_path'] ?? null);
        return $f ? (int) @filesize($f['path']) : 0;
    }

    /**
     * اسم مفهوم للتنزيل: «البطاقة الضريبية - المصرية الألمانية.pdf».
     * بنشيل الحروف اللي بتكسر أسماء الملفات على ويندوز بس، والعربي بيفضل.
     */
    public static function downloadName(array $row): string
    {
        $party = PartyMaster::find((string) ($row['party_type'] ?? ''), (int) ($row['party_id'] ?? 0));
        $parts = array_filter([
            trim((string) ($row['title'] ?? '')) ?: self::kindLabel($row['doc_kind'] ?? null),
            trim((string) ($party['name'] ?? '')),
        ]);
        $base = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|', "\n", "\r"], ' ', implode(' - ', $parts));
        $base = trim(preg_replace('/\s+/u', ' ', $base) ?? '');
        if ($base === '') { $base = 'مستند'; }
        $ext = strtolower((string) ($row['file_ext'] ?? ''));
        return mb_substr($base, 0, 120) . ($ext !== '' ? '.' . $ext : '');
    }

    public static function isPdf(array $row): bool
    {
        return strtolower((string) ($row['file_ext'] ?? '')) === 'pdf';
    }

    private static function nullable($v, int $max): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private static function date($v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') { return null; }
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }
}
