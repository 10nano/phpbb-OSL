<?php

namespace tennano\osl\service;

/** PDF renderer for complete OSL standings tables. Supports system TrueType Unicode fonts. */
class pdf_writer
{
    private $fontData = '';
    private $tables = [];
    private $cmap = 0;
    private $metrics = 0;
    private $metricCount = 0;
    private $upem = 1000;
    private $used = [];
    private $objects = [];
    private $fontObj = 0;
    private $fontPath = '';

    private function u16(int $off): int { return unpack('n', substr($this->fontData, $off, 2))[1]; }
    private function u32(int $off): int { return unpack('N', substr($this->fontData, $off, 4))[1]; }
    private function s16(int $off): int { $v = $this->u16($off); return $v > 32767 ? $v - 65536 : $v; }

    private function prepare_font(): void
    {
        $paths = [
            'C:/Windows/Fonts/arial.ttf',
            'C:/Windows/Fonts/segoeui.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
            '/Library/Fonts/Arial.ttf',
        ];
        foreach ($paths as $path)
        {
            if (is_readable($path) && ($bytes = file_get_contents($path)) && strlen($bytes) < 15000000)
            {
                $this->fontData = $bytes;
                $this->fontPath = $path;
                break;
            }
        }
        if ($this->fontData === '') { return; }
        $num = $this->u16(4);
        for ($i = 0; $i < $num; $i++)
        {
            $offset = 12 + $i * 16;
            $tag = substr($this->fontData, $offset, 4);
            $this->tables[$tag] = $this->u32($offset + 8);
        }
        foreach (['cmap', 'head', 'hhea', 'hmtx'] as $tag)
        {
            if (!isset($this->tables[$tag])) { $this->fontData = ''; return; }
        }
        $this->upem = max(1, $this->u16($this->tables['head'] + 18));
        $this->metricCount = $this->u16($this->tables['hhea'] + 34);
        $this->metrics = $this->tables['hmtx'];
        $cmapBase = $this->tables['cmap'];
        $numTables = $this->u16($cmapBase + 2);
        $picked = 0;
        for ($i = 0; $i < $numTables; $i++)
        {
            $entry = $cmapBase + 4 + 8 * $i;
            $platform = $this->u16($entry);
            $encoding = $this->u16($entry + 2);
            $base = $cmapBase + $this->u32($entry + 4);
            if (($platform === 3 && $encoding === 1 || $platform === 0) && $this->u16($base) === 4)
            {
                $picked = $base;
                if ($platform === 3 && $encoding === 1) { break; }
            }
        }
        if (!$picked) { $this->fontData = ''; return; }
        $this->cmap = $picked;
    }

    private function glyph(int $cp): int
    {
        if (!$this->cmap || $cp > 65535) { return 0; }
        $offset = $this->cmap;
        $segments = $this->u16($offset + 6) / 2;
        $ends = $offset + 14;
        $starts = $ends + 2 * $segments + 2;
        $deltas = $starts + 2 * $segments;
        $ranges = $deltas + 2 * $segments;
        for ($i = 0; $i < $segments; $i++)
        {
            if ($cp > $this->u16($ends + 2 * $i)) { continue; }
            if ($cp < $this->u16($starts + 2 * $i)) { return 0; }
            $delta = $this->s16($deltas + 2 * $i);
            $range = $this->u16($ranges + 2 * $i);
            if (!$range) { return ($cp + $delta) & 65535; }
            $pos = $ranges + 2 * $i + $range + 2 * ($cp - $this->u16($starts + 2 * $i));
            if ($pos + 2 > strlen($this->fontData)) { return 0; }
            $glyph = $this->u16($pos);
            return $glyph ? ($glyph + $delta) & 65535 : 0;
        }
        return 0;
    }

    private function hex_text(string $str): string
    {
        if ($this->fontData === '')
        {
            $text = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
            return '(' . str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $text === false ? '?' : $text) . ')';
        }
        $binary = @iconv('UTF-8', 'UTF-16BE//IGNORE', $str);
        if ($binary === false) { $binary = ''; }
        $hex = '';
        foreach (array_values(unpack('n*', $binary) ?: []) as $code)
        {
            if ($code >= 0xD800 && $code <= 0xDFFF) { $code = 63; }
            $g = $this->glyph($code);
            $hex .= sprintf('%04X', $g);
            $this->used[$g] = $code;
        }
        return '<' . $hex . '>';
    }

    private function add(string $body): int { $this->objects[] = $body; return count($this->objects); }
    private function reserve(): int { return $this->add(''); }
    private function put(int $id, string $body): void { $this->objects[$id - 1] = $body; }
    private function compressed(string $bytes, string $dict = ''): string
    {
        $compressed = gzcompress($bytes, 6);
        return '<< /Length ' . strlen($compressed) . ' /Filter /FlateDecode ' . $dict . ' >>' . "\nstream\n" . $compressed . "\nendstream";
    }
    private function rect(float $x, float $y, float $w, float $h, string $color): string
    {
        return $color . ' rg ' . sprintf('%.2F %.2F %.2F %.2F re f', $x, $y, $w, $h) . "\n";
    }
    private function line(float $x1, float $y1, float $x2, float $y2, bool $vertical = false): string
    {
        return ($vertical ? '0.20 G 1.00 w ' : '0.38 G 0.45 w ') . sprintf('%.2F %.2F m %.2F %.2F l S', $x1, $y1, $x2, $y2) . "\n";
    }
    /** Paint continuous vertical borders on top of the table cell fills. */
    private function vertical_separator(float $x, float $bottom, float $top, bool $header): string
    {
        $style = $header ? '0.88 G 1.40 w ' : '0.12 G 1.40 w ';
        return $style . sprintf('%.2F %.2F m %.2F %.2F l S', $x, $bottom, $x, $top) . "\n";
    }
    private function text(float $x, float $y, string $value, int $size = 9, string $color = '0.1'): string
    {
        $value = preg_replace('/[\x00-\x1F]/u', ' ', $value) ?? '';
        return $color . ' g BT /F1 ' . $size . ' Tf ' . sprintf('1 0 0 1 %.2F %.2F Tm ', $x, $y) . $this->hex_text($value) . " Tj ET\n";
    }
    /** Measure a numeric rank so its right edge can align with the column. */
    private function rank_width(string $value, int $font_size = 8): float
    {
        // Helvetica's tabular digits are 556/1000 em wide.
        if ($this->fontData === '' || !$this->metricCount)
        {
            return strlen($value) * $font_size * 0.556;
        }

        $width = 0.0;
        foreach (str_split($value) as $char)
        {
            $glyph = $this->glyph(ord($char));
            $index = min($glyph, $this->metricCount - 1);
            $width += $this->u16($this->metrics + $index * 4) * $font_size / $this->upem;
        }
        return $width;
    }

    private function abbreviate(string $value, int $max): string
    {
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (!$chars || count($chars) <= $max) { return $value; }
        return implode('', array_slice($chars, 0, max(0, $max - 1))) . '…';
    }

    private function column_width(array $column): float
    {
        if ($column['key'] === 'nick') { return 90.0; }
        if ($column['key'] === 'group') { return 65.0; }
        if ($column['key'] === 'rank') { return 45.0; }
        if ($column['key'] === 'starts') { return 46.0; }
        if ($column['key'] === 'sum_x') { return 57.0; }
        if (preg_match('/^[px]\\d+$/', $column['key'])) { return preg_match('/\\b\\d{4}\\b/', $column['title']) ? 78.0 : 46.0; }
        return 62.0;
    }

    /**
     * Keep every column and every competitor on the same PDF page.
     * A competition gets its own page, sized to the complete table.
     */
    private function table_pages(array $report): array
    {
        $pages = [];
        $margin = 40.0;
        $headerH = 26.0;
        $rowH = 21.0;

        foreach ($report['competitions'] as $competition)
        {
            $columns = $competition['columns'];
            $rows = $competition['rows'];
            $widths = [];
            $tableW = 0.0;
            foreach ($columns as $column)
            {
                $width = $this->column_width($column);
                $widths[] = $width;
                $tableW += $width;
            }

            // The minimum is the original A3 landscape canvas. Wider/taller
            // standings use a larger page instead of cutting months or rows.
            $pageW = max(1191.0, $tableW + 2 * $margin);
            $pageH = max(842.0, 185.0 + $headerH + $rowH * count($rows));
            $y = $pageH - 138.0;
            $x = $margin;

            $out = '';
            $out .= $this->text($margin, $pageH - 52.0, $this->abbreviate($report['title'], 92), 17);
            $out .= $this->text($margin, $pageH - 75.0, $this->abbreviate($competition['title'], 95), 13);
            $out .= $this->text($pageW - 160.0, $pageH - 47.0, date('Y-m-d'), 9);

            $headerBottom = $y;

            foreach ($columns as $index => $column)
            {
                $width = $widths[$index];
                $out .= $this->rect($x, $y, $width, $headerH, '0.18 0.18 0.18');
                // Leave the first column unchanged; indent every other header by 12 pt.
                $header_padding = $index === 0 ? 3.0 : 15.0;
                $out .= $this->text($x + $header_padding, $y + 9.0,
                    $this->abbreviate($column['title'],
                        max(2, (int) (($width - $header_padding - 3.0) / 5.5))), 8, '1');
                // The full-height separator is drawn after all cell backgrounds.
                $x += $width;
            }
            $out .= $this->line($margin, $y, $x, $y);

            foreach ($rows as $index => $record)
            {
                $y -= $rowH;
                $x = $margin;
                foreach ($columns as $j => $column)
                {
                    $width = $widths[$j];
                    $out .= $this->rect($x, $y, $width, $rowH,
                        $index % 2 ? '0.95 0.95 0.95' : '0.84 0.84 0.84');
                    $value = $record[$column['key']] ?? null;
                    $display = $value === null ? '-' : (string) $value;
                    // Indent non-ranking columns 12 pt further without moving rank numbers.
                    $is_rank = $column['key'] === 'rank';
                    $padding = $is_rank ? 3.0 : 15.0;
                    $display = $this->abbreviate($display,
                        max(2, (int) (($width - $padding - 3.0) / 5.0)));
                    // Keep numeric rankings right-aligned at their existing position.
                    $text_x = $is_rank && ctype_digit($display)
                        ? $x + $width - 18.0 - $this->rank_width($display)
                        : $x + $padding;
                    $out .= $this->text($text_x, $y + 7.0, $display, 8);
                    // The full-height separator is drawn after all cell backgrounds.
                    $x += $width;
                }
                $out .= $this->line($margin, $y, $x, $y);
            }

            // Draw the separators last: otherwise the next cell fill can obscure
            // half of each line. Light lines divide the dark header, dark lines
            // divide the alternating body rows.
            $separatorX = $margin;
            foreach ($widths as $width)
            {
                $out .= $this->vertical_separator($separatorX, $y, $headerBottom, false);
                $out .= $this->vertical_separator($separatorX, $headerBottom, $headerBottom + $headerH, true);
                $separatorX += $width;
            }
            $out .= $this->vertical_separator($separatorX, $y, $headerBottom, false);
            $out .= $this->vertical_separator($separatorX, $headerBottom, $headerBottom + $headerH, true);

            $out .= $this->text($margin, 32.0, 'OSL | ' . $competition['title'], 9);
            $pages[] = ['content' => $out, 'width' => $pageW, 'height' => $pageH];
        }

        if (!$pages)
        {
            $pages[] = ['content' => $this->text(40, 790, $report['title'], 16),
                'width' => 1191.0, 'height' => 842.0];
        }
        return $pages;
    }

    private function font_objects(): void
    {
        if ($this->fontData === '')
        {
            $this->put($this->fontObj, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
            return;
        }
        $ttf = $this->compressed($this->fontData, '/Length1 ' . strlen($this->fontData));
        $ttfObj = $this->add($ttf);
        $descriptor = $this->add('<< /Type /FontDescriptor /FontName /OSLUnicode /Flags 32 /FontBBox [-1000 -300 2000 1200] /ItalicAngle 0 /Ascent 950 /Descent -250 /CapHeight 760 /StemV 90 /FontFile2 ' . $ttfObj . ' 0 R >>');
        ksort($this->used);
        $widths = [];
        $cmap = [];
        foreach ($this->used as $gid => $code)
        {
            $index = min($gid, $this->metricCount - 1);
            $width = (int) round($this->u16($this->metrics + $index * 4) * 1000 / $this->upem);
            $widths[] = $gid . ' [' . $width . ']';
            $cmap[] = '<' . sprintf('%04X', $gid) . '> <' . sprintf('%04X', $code) . '>';
        }
        $cid = $this->add('<< /Type /Font /Subtype /CIDFontType2 /BaseFont /OSLUnicode /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor ' . $descriptor . ' 0 R /CIDToGIDMap /Identity /DW 500 /W [' . implode(' ', $widths) . '] >>');
        $unicode = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n/CMapName /OSLUnicodeMap def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";
        foreach (array_chunk($cmap, 90) as $chunk)
        {
            $unicode .= count($chunk) . " beginbfchar\n" . implode("\n", $chunk) . "\nendbfchar\n";
        }
        $unicode .= "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
        $toUnicode = $this->add($this->compressed($unicode));
        $this->put($this->fontObj, '<< /Type /Font /Subtype /Type0 /BaseFont /OSLUnicode /Encoding /Identity-H /DescendantFonts [' . $cid . ' 0 R] /ToUnicode ' . $toUnicode . ' 0 R >>');
    }

    public function pdf(array $report): string
    {
        $this->prepare_font();
        $this->fontObj = $this->reserve();
        $pagesObj = $this->reserve();
        $pages = [];
        foreach ($this->table_pages($report) as $page)
        {
            // PDF page dimensions are limited to 14,400 points. In the rare
            // case of an exceptionally large table, scale the whole page as
            // one unit; never split its columns or rows.
            $scale = min(1.0, 14400.0 / max($page['width'], $page['height']));
            $width = $page['width'] * $scale;
            $height = $page['height'] * $scale;
            $content = $page['content'];
            if ($scale < 1.0)
            {
                $content = sprintf("q %.8F 0 0 %.8F 0 0 cm\n", $scale, $scale) . $content . "Q\n";
            }
            $contentObj = $this->add($this->compressed($content));
            $mediaBox = sprintf('[0 0 %.2F %.2F]', $width, $height);
            $pageObj = $this->add('<< /Type /Page /Parent ' . $pagesObj . ' 0 R /MediaBox ' . $mediaBox . ' /Resources << /Font << /F1 ' . $this->fontObj . ' 0 R >> >> /Contents ' . $contentObj . ' 0 R >>');
            $pages[] = $pageObj . ' 0 R';
        }
        $this->put($pagesObj, '<< /Type /Pages /Kids [' . implode(' ', $pages) . '] /Count ' . count($pages) . ' >>');
        $catalog = $this->add('<< /Type /Catalog /Pages ' . $pagesObj . ' 0 R >>');
        $this->font_objects();
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($this->objects as $i => $object)
        {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($this->objects) + 1) . "\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset)
        {
            $pdf .= sprintf('%010d 00000 n ', $offset) . "\n";
        }
        $pdf .= 'trailer << /Size ' . (count($this->objects) + 1) . ' /Root ' . $catalog . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $pdf;
    }
}
