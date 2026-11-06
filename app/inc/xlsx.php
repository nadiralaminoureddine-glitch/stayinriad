<?php
// StayInRiad — petit générateur de fichiers Excel (.xlsx) sans bibliothèque externe :
// une feuille, cellules texte / nombre / date / formule, styles fixes aux couleurs du site, logo en en-tête.
declare(strict_types=1);
if (!defined('SIR_APP')) exit;

/** Styles disponibles (index dans cellXfs, voir xlsx_styles()). */
const XS = ['def' => 0, 'title' => 1, 'sub' => 2, 'head' => 3, 'txt' => 4, 'txtz' => 5, 'date' => 6, 'datez' => 7, 'eur' => 8, 'eurz' => 9,
    'mad' => 10, 'madz' => 11, 'int' => 12, 'intz' => 13, 'pct' => 14, 'pctz' => 15, 'totl' => 16, 'tote' => 17, 'totm' => 18, 'toti' => 19,
    'kl' => 20, 'ke' => 21, 'km' => 22, 'ki' => 23, 'note' => 24, 'band' => 25, 'rate' => 26, 'ratez' => 27];

function xlsx_col(int $i): string { $s = ''; $i++; while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); } return $s; }
function xlsx_esc(string $s): string { return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
/** Date 'Y-m-d' (ou datetime) -> numéro de série Excel. */
function xlsx_date(?string $d): ?float { if (!$d) return null; $t = strtotime(substr($d, 0, 10) . ' UTC'); return $t === false ? null : $t / 86400 + 25569; }

/** Une cellule : ['v' => valeur, 's' => style, 'f' => formule]. Les chaînes deviennent du texte, les nombres des nombres. */
function xlsx_cell(string $ref, $c): string {
    if (!is_array($c)) $c = ['v' => $c];
    $s = XS[$c['s'] ?? 'def'] ?? 0;
    if (isset($c['f'])) return '<c r="' . $ref . '" s="' . $s . '"><f>' . xlsx_esc($c['f']) . '</f></c>';
    $v = $c['v'] ?? null;
    if ($v === null || $v === '') return '<c r="' . $ref . '" s="' . $s . '"/>';
    if (is_int($v) || is_float($v)) return '<c r="' . $ref . '" s="' . $s . '"><v>' . rtrim(rtrim(sprintf('%.6F', $v), '0'), '.') . '</v></c>';
    return '<c r="' . $ref . '" s="' . $s . '" t="inlineStr"><is><t xml:space="preserve">' . xlsx_esc((string)$v) . '</t></is></c>';
}

function xlsx_styles(): string {
    $fonts = ['<font><sz val="11"/><color rgb="FF1F1A17"/><name val="Calibri"/></font>', '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>',
        '<font><b/><sz val="18"/><color rgb="FF2647A8"/><name val="Calibri"/></font>', '<font><sz val="10"/><color rgb="FF6B625A"/><name val="Calibri"/></font>',
        '<font><b/><sz val="11"/><color rgb="FF1F1A17"/><name val="Calibri"/></font>', '<font><b/><sz val="14"/><color rgb="FF2647A8"/><name val="Calibri"/></font>',
        '<font><i/><sz val="9"/><color rgb="FF6B625A"/><name val="Calibri"/></font>'];
    $fill = fn($rgb) => '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $rgb . '"/><bgColor indexed="64"/></patternFill></fill>';
    $fills = ['<fill><patternFill patternType="none"/></fill>', '<fill><patternFill patternType="gray125"/></fill>', $fill('2647A8'), $fill('F7EFE3'), $fill('FBF7F0')];
    $borders = ['<border><left/><right/><top/><bottom/><diagonal/></border>', '<border><left/><right/><top/><bottom style="thin"><color rgb="FFE8DCC8"/></bottom><diagonal/></border>',
        '<border><left/><right/><top style="medium"><color rgb="FF2647A8"/></top><bottom style="medium"><color rgb="FF2647A8"/></bottom><diagonal/></border>'];
    $fmts = ['<numFmt numFmtId="164" formatCode="#,##0.00\ &quot;€&quot;"/>', '<numFmt numFmtId="165" formatCode="#,##0.00\ &quot;MAD&quot;"/>',
        '<numFmt numFmtId="166" formatCode="dd/mm/yyyy"/>', '<numFmt numFmtId="167" formatCode="0.00\ &quot;%&quot;"/>', '<numFmt numFmtId="168" formatCode="0.0000"/>'];
    // [numFmt, font, fill, border, align]
    $x = [[0, 0, 0, 0, ''], [0, 2, 0, 0, ''], [0, 3, 0, 0, ''], [0, 1, 2, 0, 'center|wrap'],
        [0, 0, 0, 1, ''], [0, 0, 4, 1, ''], [166, 0, 0, 1, 'center'], [166, 0, 4, 1, 'center'], [164, 0, 0, 1, ''], [164, 0, 4, 1, ''],
        [165, 0, 0, 1, ''], [165, 0, 4, 1, ''], [1, 0, 0, 1, 'center'], [1, 0, 4, 1, 'center'], [167, 0, 0, 1, 'center'], [167, 0, 4, 1, 'center'],
        [0, 4, 3, 2, ''], [164, 4, 3, 2, ''], [165, 4, 3, 2, ''], [1, 4, 3, 2, 'center'],
        [0, 3, 3, 0, 'center|wrap'], [164, 5, 3, 0, 'center'], [165, 5, 3, 0, 'center'], [1, 5, 3, 0, 'center'], [0, 6, 0, 0, ''], [0, 0, 2, 0, ''],
        [168, 0, 0, 1, 'center'], [168, 0, 4, 1, 'center']];
    $xfs = implode('', array_map(function ($a) {
        [$n, $f, $fi, $b, $al] = $a;
        $attr = ' numFmtId="' . $n . '" fontId="' . $f . '" fillId="' . $fi . '" borderId="' . $b . '" xfId="0"' . ($n ? ' applyNumberFormat="1"' : '') . ($f ? ' applyFont="1"' : '') . ($fi ? ' applyFill="1"' : '') . ($b ? ' applyBorder="1"' : '');
        if ($al === '') return '<xf' . $attr . '/>';
        $h = str_contains($al, 'center') ? ' horizontal="center"' : ''; $w = str_contains($al, 'wrap') ? ' wrapText="1"' : '';
        return '<xf' . $attr . ' applyAlignment="1"><alignment vertical="center"' . $h . $w . '/></xf>';
    }, $x));
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="' . count($fmts) . '">' . implode('', $fmts) . '</numFmts><fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts>'
        . '<fills count="' . count($fills) . '">' . implode('', $fills) . '</fills><borders count="' . count($borders) . '">' . implode('', $borders) . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="' . count($x) . '">' . $xfs . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
}

/** Archive ZIP (sans compression) à partir de [chemin => contenu]. */
function xlsx_zip(array $files): string {
    $data = ''; $dir = ''; $n = 0; $dos = [0x21, 0x00]; // date/heure DOS fixes
    foreach ($files as $name => $body) {
        $crc = crc32($body); $len = strlen($body); $off = strlen($data);
        $h = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, 0, 0x5821, $crc, $len, $len, strlen($name), 0);
        $data .= $h . $name . $body;
        $dir .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, 0, 0x5821, $crc, $len, $len, strlen($name), 0, 0, 0, 0, 0, $off) . $name;
        $n++;
    }
    return $data . $dir . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($dir), strlen($data), 0);
}

/**
 * Construit le classeur. $rows : [numéro de ligne => [colonne(int) => cellule]], $widths : largeurs par colonne,
 * $opt : merges, heights, freeze (ligne), filter (plage), logo (chemin PNG), logoSize [l, h] en px, sheet (nom), title.
 */
function xlsx_build(array $rows, array $widths, array $opt = []): string {
    ksort($rows);
    $sd = '';
    foreach ($rows as $r => $cells) {
        ksort($cells);
        $ht = isset($opt['heights'][$r]) ? ' ht="' . $opt['heights'][$r] . '" customHeight="1"' : '';
        $sd .= '<row r="' . $r . '"' . $ht . '>' . implode('', array_map(fn($c, $v) => xlsx_cell(xlsx_col($c) . $r, $v), array_keys($cells), $cells)) . '</row>';
    }
    $cols = implode('', array_map(fn($i, $w) => '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>', array_keys($widths), $widths));
    $pane = !empty($opt['freeze']) ? '<sheetViews><sheetView workbookViewId="0" showGridLines="0"><pane ySplit="' . $opt['freeze'] . '" topLeftCell="A' . ($opt['freeze'] + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        : '<sheetViews><sheetView workbookViewId="0" showGridLines="0"/></sheetViews>';
    $merges = !empty($opt['merges']) ? '<mergeCells count="' . count($opt['merges']) . '">' . implode('', array_map(fn($m) => '<mergeCell ref="' . $m . '"/>', $opt['merges'])) . '</mergeCells>' : '';
    $logo = !empty($opt['logo']) && is_file($opt['logo']);
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>' . $pane . '<sheetFormatPr defaultRowHeight="18"/><cols>' . $cols . '</cols><sheetData>' . $sd . '</sheetData>'
        . (!empty($opt['filter']) ? '<autoFilter ref="' . $opt['filter'] . '"/>' : '') . $merges
        . '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/><pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0" paperSize="9"/>'
        . ($logo ? '<drawing r:id="rId1"/>' : '') . '</worksheet>';
    $name = xlsx_esc(mb_substr($opt['sheet'] ?? 'Relevé', 0, 31));
    $f = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . ($logo ? '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>' : '')
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>',
        'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . xlsx_esc($opt['title'] ?? 'Relevé StayInRiad') . '</dc:title><dc:creator>StayInRiad</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $name . '" sheetId="1" r:id="rId1"/></sheets>'
            . (!empty($opt['filter']) ? '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'' . $name . '\'!' . preg_replace('/([A-Z]+)(\d+)/', '\$$1\$$2', $opt['filter']) . '</definedName></definedNames>' : '') . '</workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => xlsx_styles(),
        'xl/worksheets/sheet1.xml' => $sheet,
    ];
    if ($logo) {
        [$w, $h] = $opt['logoSize'] ?? [290, 64];
        $f['xl/worksheets/_rels/sheet1.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>';
        $f['xl/drawings/_rels/drawing1.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image1.png"/></Relationships>';
        $f['xl/drawings/drawing1.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<xdr:oneCellAnchor><xdr:from><xdr:col>0</xdr:col><xdr:colOff>114300</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>95250</xdr:rowOff></xdr:from><xdr:ext cx="' . ($w * 9525) . '" cy="' . ($h * 9525) . '"/>'
            . '<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="2" name="Logo StayInRiad"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr><xdr:blipFill><a:blip r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
            . '<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . ($w * 9525) . '" cy="' . ($h * 9525) . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/></xdr:oneCellAnchor></xdr:wsDr>';
        $f['xl/media/image1.png'] = (string)file_get_contents($opt['logo']);
    }
    return xlsx_zip($f);
}
