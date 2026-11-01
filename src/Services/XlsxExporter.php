<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Vrai classeur Excel écrit à la main, sans bibliothèque : un CSV ne porte aucune mise en forme.
 * Ligne de titres grise et en gras, figée, avec filtres. Les textes restent des textes : un numéro
 * de téléphone ne peut donc pas devenir 2,2901E+12.
 */
final class XlsxExporter
{
    private const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public static function type(): string
    {
        return self::MIME;
    }

    /** @param string[] $entetes @param iterable<array> $lignes */
    public static function tableau(string $feuille, array $entetes, iterable $lignes): string
    {
        $largeurs = array_map(static fn(string $e): int => mb_strlen($e), $entetes);
        $xml = self::ligne(1, $entetes, 1, $largeurs);
        $n = 1;
        foreach ($lignes as $l) {
            $n++;
            $xml .= self::ligne($n, array_values($l), 0, $largeurs);
        }
        $colonnes = '';
        foreach ($largeurs as $i => $l) {
            $colonnes .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . min(45, $l + 3) . '" customWidth="1"/>';
        }
        $derniere = self::colonne(count($entetes) - 1);
        $feuilleXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . "<cols>$colonnes</cols><sheetData>$xml</sheetData><autoFilter ref=\"A1:{$derniere}{$n}\"/></worksheet>";
        $nom = self::xml(mb_substr(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $feuille), 0, 31));
        return self::zip([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $nom . '" sheetId="1" r:id="rId1"/></sheets><definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'' . str_replace("'", "''", $nom) . "'!\$A\$1:\$$derniere\$$n</definedName></definedNames></workbook>",
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFD9D9D9"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
            'xl/worksheets/sheet1.xml' => $feuilleXml,
        ]);
    }

    private static function ligne(int $n, array $valeurs, int $style, array &$largeurs): string
    {
        $cellules = '';
        foreach ($valeurs as $i => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $ref = self::colonne($i) . $n;
            $s = $style ? ' s="1"' : '';
            $largeurs[$i] = max($largeurs[$i] ?? 0, mb_strlen((string) $v));
            $cellules .= is_int($v)
                ? "<c r=\"$ref\"$s><v>$v</v></c>"
                : "<c r=\"$ref\"$s t=\"inlineStr\"><is><t xml:space=\"preserve\">" . self::xml((string) $v) . '</t></is></c>';
        }
        return "<row r=\"$n\">$cellules</row>";
    }

    private static function colonne(int $i): string
    {
        $nom = '';
        for ($i++; $i > 0; $i = (int) (($i - 1) / 26)) {
            $nom = chr(65 + ($i - 1) % 26) . $nom;
        }
        return $nom;
    }

    private static function xml(string $s): string
    {
        return htmlspecialchars((string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Archive ZIP minimale : l'extension PHP zip n'est pas garantie chez un hébergeur mutualisé. */
    private static function zip(array $fichiers): string
    {
        $corps = '';
        $central = '';
        foreach ($fichiers as $nom => $contenu) {
            $crc = crc32($contenu);
            $comprime = gzdeflate($contenu, 6);
            $entete = pack('vvvvvVVVvv', 20, 0x0800, 8, 0, 0x21, $crc, strlen($comprime), strlen($contenu), strlen($nom), 0);
            $central .= "PK\x01\x02" . pack('v', 20) . $entete . pack('vvvVV', 0, 0, 0, 0, strlen($corps)) . $nom;
            $corps .= "PK\x03\x04" . $entete . $nom . $comprime;
        }
        return $corps . $central . "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($fichiers), count($fichiers), strlen($central), strlen($corps), 0);
    }
}
