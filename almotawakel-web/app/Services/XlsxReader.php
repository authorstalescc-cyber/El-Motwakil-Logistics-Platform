<?php
namespace App\Services;

/**
 * قارئ XLSX صغير للمعاينات فقط.
 *
 * لا يفتح Excel ولا يعيد حفظ الملف؛ يقرأ XML المضغوط قراءة فقط، مع حدود
 * صريحة للحجم وعدد الصفوف. المقصود تجنّب إضافة مكتبة كاملة لمجرد قراءة
 * الأعمدة المطلوبة من ورقة «بوالص».
 */
class XlsxReader
{
    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const REL_NS  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private \ZipArchive $zip;
    /** @var array<int,string>|null */
    private ?array $sharedStrings = null;

    public function __construct(private readonly string $path)
    {
        if (!class_exists(\ZipArchive::class) || !class_exists(\XMLReader::class)) {
            throw new \RuntimeException('امتدادا ZIP وXMLReader مطلوبان لقراءة ملف Excel.');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('ملف Excel غير موجود أو غير قابل للقراءة.');
        }
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new \RuntimeException('مصدر المزامنة يجب أن يكون ملف XLSX.');
        }
        $size = filesize($path);
        if ($size === false || $size > 30 * 1024 * 1024) {
            throw new \RuntimeException('حجم ملف Excel يتجاوز الحد الآمن للمعاينة (30 MB).');
        }

        $this->zip = new \ZipArchive();
        if ($this->zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('تعذّر فتح ملف Excel.');
        }
    }

    public function __destruct()
    {
        if (isset($this->zip)) { $this->zip->close(); }
    }

    /**
     * يعيد الصفوف كمصفوفة مفهرسة برقم الصف، وكل صف مفهرس بحرف العمود.
     * الأعمدة خارج $maxColumn تُتجاهل قبل فك قيمتها.
     *
     * @return array<int,array<string,mixed>>
     */
    public function rows(string $sheetName, int $maxRow = 10000, string $maxColumn = 'AF'): array
    {
        $sheetPart = $this->sheetPart($sheetName);
        $xml = $this->entry($sheetPart);
        $reader = new \XMLReader();
        if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new \RuntimeException('تعذّرت قراءة ورقة Excel.');
        }

        $maxColNo = self::columnNumber($maxColumn);
        $rows = [];
        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'c') {
                continue;
            }
            $ref = (string) $reader->getAttribute('r');
            if (!preg_match('/^([A-Z]+)(\d+)$/', strtoupper($ref), $m)) {
                continue;
            }
            $column = $m[1];
            $rowNo = (int) $m[2];
            if ($rowNo < 1 || $rowNo > $maxRow || self::columnNumber($column) > $maxColNo) {
                continue;
            }

            $type = (string) ($reader->getAttribute('t') ?? '');
            $cellXml = $reader->readOuterXml();
            $value = $this->cellValue($cellXml, $type);
            if ($value !== null && $value !== '') {
                $rows[$rowNo][$column] = $value;
            }
        }
        $reader->close();
        ksort($rows);
        return $rows;
    }

    /** اسم ملف XML الخاص بالورقة كما هو مخزّن داخل XLSX. */
    private function sheetPart(string $sheetName): string
    {
        $workbook = $this->xml($this->entry('xl/workbook.xml'), 'بنية ملف Excel غير صحيحة.');
        $workbook->registerXPathNamespace('m', self::MAIN_NS);
        $workbook->registerXPathNamespace('r', self::REL_NS);
        $rid = '';
        foreach ($workbook->xpath('//m:sheets/m:sheet') ?: [] as $sheet) {
            if ((string) $sheet['name'] !== $sheetName) { continue; }
            $attrs = $sheet->attributes(self::REL_NS);
            $rid = (string) ($attrs['id'] ?? '');
            break;
        }
        if ($rid === '') {
            throw new \RuntimeException('ورقة «' . $sheetName . '» غير موجودة في ملف Excel.');
        }

        $rels = $this->xml(
            $this->entry('xl/_rels/workbook.xml.rels'),
            'علاقات أوراق Excel غير صحيحة.'
        );
        $rels->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $target = '';
        foreach ($rels->xpath('//p:Relationship') ?: [] as $rel) {
            $attrs = $rel->attributes();
            if ((string) ($attrs['Id'] ?? '') === $rid) {
                $target = (string) ($attrs['Target'] ?? '');
                break;
            }
        }
        if ($target === '') {
            throw new \RuntimeException('تعذّر تحديد ملف ورقة «' . $sheetName . '».');
        }

        $target = str_replace('\\', '/', $target);
        $part = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
        $part = self::normalizePart($part);
        if (!str_starts_with($part, 'xl/worksheets/')) {
            throw new \RuntimeException('مسار ورقة Excel غير آمن.');
        }
        return $part;
    }

    private function cellValue(string $cellXml, string $type): mixed
    {
        $cell = $this->xml($cellXml, 'خلية غير صالحة داخل Excel.');
        $cell->registerXPathNamespace('m', self::MAIN_NS);

        if ($type === 'inlineStr') {
            $texts = $cell->xpath('.//m:is//m:t') ?: [];
            return implode('', array_map(static fn($t) => (string) $t, $texts));
        }

        $values = $cell->xpath('./m:v') ?: [];
        if (!$values) { return null; }
        $raw = (string) $values[0];
        if ($type === 's') {
            $strings = $this->sharedStrings();
            return $strings[(int) $raw] ?? '';
        }
        if ($type === 'str' || $type === 'e') { return $raw; }
        if ($type === 'b') { return $raw === '1'; }
        return is_numeric($raw) ? (float) $raw : $raw;
    }

    /** @return array<int,string> */
    private function sharedStrings(): array
    {
        if ($this->sharedStrings !== null) { return $this->sharedStrings; }
        $this->sharedStrings = [];
        if ($this->zip->locateName('xl/sharedStrings.xml') === false) {
            return $this->sharedStrings;
        }

        $reader = new \XMLReader();
        if (!$reader->XML($this->entry('xl/sharedStrings.xml'), null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new \RuntimeException('تعذّرت قراءة نصوص Excel.');
        }
        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'si') {
                continue;
            }
            $si = $this->xml($reader->readOuterXml(), 'نص غير صالح داخل Excel.');
            $si->registerXPathNamespace('m', self::MAIN_NS);
            $texts = $si->xpath('.//m:t') ?: [];
            $this->sharedStrings[] = implode('', array_map(static fn($t) => (string) $t, $texts));
        }
        $reader->close();
        return $this->sharedStrings;
    }

    private function entry(string $name): string
    {
        $stat = $this->zip->statName($name);
        if ($stat === false || (int) ($stat['size'] ?? 0) > 80 * 1024 * 1024) {
            throw new \RuntimeException('جزء Excel مفقود أو يتجاوز الحد الآمن: ' . basename($name));
        }
        $content = $this->zip->getFromName($name);
        if ($content === false) {
            throw new \RuntimeException('جزء مفقود داخل ملف Excel: ' . basename($name));
        }
        return $content;
    }

    private function xml(string $content, string $error): \SimpleXMLElement
    {
        $old = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($content, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
        if (!$xml) { throw new \RuntimeException($error); }
        return $xml;
    }

    private static function columnNumber(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $letter) {
            $n = ($n * 26) + (ord($letter) - 64);
        }
        return $n;
    }

    private static function normalizePart(string $part): string
    {
        $out = [];
        foreach (explode('/', $part) as $piece) {
            if ($piece === '' || $piece === '.') { continue; }
            if ($piece === '..') {
                if (!$out) { throw new \RuntimeException('مسار داخلي غير آمن في Excel.'); }
                array_pop($out);
                continue;
            }
            $out[] = $piece;
        }
        return implode('/', $out);
    }
}
