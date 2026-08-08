<?php
namespace App\Models;

use App\Core\App;

/**
 * أسماء بديلة للقيم الوصفية التي لا تملك سجلًا قانونيًا مستقلًا.
 *
 * مثال:
 *   driver         «جلال الزين» ← «جلال حسني»
 *   container_type «40»         ← «40 قدم»
 *
 * الهدف ليس التخمين: لا يُضاف أي اسم بديل إلا بقرار صريح من شاشة تنظيف
 * البيانات. بعد ذلك فقط يُوحَّد عند الإدخال، فلا تعود الغلطة نفسها.
 */
class DataAlias
{
    private const SETTING_KEY = 'data_aliases';

    /**
     * @var array<string,array<string,array{alias_value:string,canonical_value:string,canonical_key:string}>>|null
     */
    private static ?array $cache = null;

    public static function clearCache(): void
    {
        self::$cache = null;
    }

    /**
     * الاسم المعتمد لاسم بديل، أو null إن لم يوجد قرار دمج سابق.
     *
     * يتبع السلسلة دفاعيًا (A→B→C) بحد أقصى 8 خطوات، مع منع الدوران.
     */
    public static function canonical(string $fieldKey, string $raw): ?string
    {
        $key = Maintenance::normkey($raw);
        if ($key === '') { return null; }

        $map = self::map();
        $seen = [];
        $value = null;
        for ($i = 0; $i < 8 && isset($map[$fieldKey][$key]); $i++) {
            if (isset($seen[$key])) { return null; }
            $seen[$key] = true;
            $value = (string) $map[$fieldKey][$key]['canonical_value'];
            $next = Maintenance::normkey($value);
            if ($next === '' || $next === $key) { break; }
            $key = $next;
        }
        return $value;
    }

    /**
     * يسجّل القيم المدموجة كأسماء بديلة دائمة.
     *
     * إذا كان الاسم المعتمد نفسه اسمًا بديلًا قديمًا، نربط مباشرةً بالاسم
     * النهائي. وإذا اندمج اسم معتمد قديم داخل جديد، ننقل كل بدائله إليه.
     *
     * @param list<string> $variants
     */
    public static function remember(string $fieldKey, string $canonical, array $variants): int
    {
        $canonical = trim(self::canonical($fieldKey, $canonical) ?? $canonical);
        $canonicalKey = Maintenance::normkey($canonical);
        if ($canonicalKey === '') { return 0; }

        $aliases = [];
        foreach ($variants as $variant) {
            $variant = trim((string) $variant);
            $aliasKey = Maintenance::normkey($variant);
            if ($variant === '' || $aliasKey === '' || $aliasKey === $canonicalKey) { continue; }
            $aliases[$aliasKey] = $variant;
        }
        if (!$aliases) { return 0; }

        $map = self::map();
        $map[$fieldKey] ??= [];
        $changed = 0;
        foreach ($aliases as $aliasKey => $aliasValue) {
            // كل الأسماء التي كانت تشير إلى الاسم الجاري دمجه تنتقل للهدف الجديد.
            foreach ($map[$fieldKey] as &$entry) {
                if (($entry['canonical_key'] ?? '') === $aliasKey) {
                    $entry['canonical_value'] = $canonical;
                    $entry['canonical_key'] = $canonicalKey;
                    $changed++;
                }
            }
            unset($entry);
            $map[$fieldKey][$aliasKey] = [
                'alias_value'     => $aliasValue,
                'canonical_value' => $canonical,
                'canonical_key'   => $canonicalKey,
            ];
            $changed++;
        }
        App::setSetting(self::SETTING_KEY, json_encode(
            $map,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
        self::$cache = $map;
        return $changed;
    }

    /**
     * @return array<string,array<string,array{alias_value:string,canonical_value:string,canonical_key:string}>>
     */
    private static function map(): array
    {
        if (self::$cache !== null) { return self::$cache; }
        $decoded = json_decode((string) App::setting(self::SETTING_KEY, '{}'), true);
        self::$cache = is_array($decoded) ? $decoded : [];
        return self::$cache;
    }
}
