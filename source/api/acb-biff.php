<?php
/**
 * APSA - Sinh file "Chi Lo" ACB dang BIFF8 (.xls) bang PHP thuan, khong can thu vien ngoai.
 * Tai tao dung dinh dang file mau ChiLo_mau.xls (font, mau nen, vien, gop o, do rong cot, chieu cao dong).
 * Dau vao: mang cac dong [stt, ten, ma_nh, so_tk, so_the, so_tien, noi_dung]
 * Dau ra : chuoi nhi phan noi dung file .xls
 * PHP >= 7.1
 */

function acb_make_xls(array $rows) {
    $w = new AcbBiffWriter();
    return $w->build($rows);
}

class AcbBiffWriter {
    const NOTE = 'Lưu ý:  Quý khách vui lòng nhập thông tin không dùng dấu tiếng Việt, không thay đổi định dạng file.';
    private $head = array('STT', 'Tên đơn vị thụ hưởng', 'Mã ngân hàng', 'Số tài khoản nhận', 'Số thẻ (ACB)', 'Số tiền', "Nội dung \n");
    private $widths = array(1536, 2944, 2176, 2218, 2261, 2688, 4053);

    // XF index (cell)
    const XF_DEF = 16, XF_TITLE = 17, XF_HEAD = 18, XF_HEAD6 = 19, XF_STT = 20, XF_TXT = 21, XF_NUM = 22;

    private $sst = array();      // key => index
    private $sstList = array();  // index => encoded string
    private $sstTotal = 0;

    /* ---------- tien ich ---------- */
    private static function rec($type, $data) {
        return pack('vv', $type, strlen($data)) . $data;
    }

    // UTF-8 -> UTF-16LE (khong can mbstring/iconv)
    private static function utf16le($s) {
        $s = (string)$s; $n = strlen($s); $out = ''; $i = 0;
        while ($i < $n) {
            $b = ord($s[$i]);
            if ($b < 0x80) { $cp = $b; $i += 1; }
            elseif (($b & 0xE0) === 0xC0 && $i + 1 < $n) { $cp = (($b & 0x1F) << 6) | (ord($s[$i + 1]) & 0x3F); $i += 2; }
            elseif (($b & 0xF0) === 0xE0 && $i + 2 < $n) { $cp = (($b & 0x0F) << 12) | ((ord($s[$i + 1]) & 0x3F) << 6) | (ord($s[$i + 2]) & 0x3F); $i += 3; }
            elseif (($b & 0xF8) === 0xF0 && $i + 3 < $n) { $cp = (($b & 0x07) << 18) | ((ord($s[$i + 1]) & 0x3F) << 12) | ((ord($s[$i + 2]) & 0x3F) << 6) | (ord($s[$i + 3]) & 0x3F); $i += 4; }
            else { $cp = 0x3F; $i += 1; }
            if ($cp >= 0x10000) { $cp -= 0x10000; $out .= pack('vv', 0xD800 | ($cp >> 10), 0xDC00 | ($cp & 0x3FF)); }
            else $out .= pack('v', $cp);
        }
        return $out;
    }
    // Chuoi unicode BIFF8: [len16][flags][chars]  (8-bit neu tat ca ky tu < 256, nguoc lai UTF-16LE)
    private static function ustr16($s) {
        $u = self::utf16le($s);
        $n = intdiv(strlen($u), 2);
        $lo = ''; $ascii = true;
        for ($i = 0; $i < $n; $i++) {
            if ($u[$i * 2 + 1] !== "\x00") { $ascii = false; break; }
            $lo .= $u[$i * 2];
        }
        return $ascii ? pack('v', $n) . "\x00" . $lo : pack('v', $n) . "\x01" . $u;
    }
    // Chuoi ngan (len 8-bit) dung cho FONT / BOUNDSHEET
    private static function ustr8($s) {
        $u = self::utf16le($s);
        $n = intdiv(strlen($u), 2);
        $lo = ''; $ascii = true;
        for ($i = 0; $i < $n; $i++) {
            if ($u[$i * 2 + 1] !== "\x00") { $ascii = false; break; }
            $lo .= $u[$i * 2];
        }
        return $ascii ? chr($n) . "\x00" . $lo : chr($n) . "\x01" . $u;
    }

    private function sstIndex($s) {
        $this->sstTotal++;
        if (isset($this->sst[$s])) return $this->sst[$s];
        $i = count($this->sstList);
        $this->sst[$s] = $i;
        $this->sstList[] = self::ustr16($s);
        return $i;
    }

    private static function font($height, $bold, $name) {
        return self::rec(0x0031, pack('vvvvvCCCC', $height, $bold ? 1 : 0, 0x7FFF, $bold ? 700 : 400, 0, 0, 0, 1, 0) . self::ustr8($name));
    }

    // XF: font, align, border1, border2, fill, style?
    private static function xf($font, $align, $b1, $b2, $fill, $style = false) {
        return self::rec(0x00E0, pack('vvvCCCCVVv', $font, 0x00A4, $style ? 0xFFF5 : 0x0001, $align, 0, 0, $style ? 0xF4 : 0xF8, $b1, $b2, $fill));
    }

    private function cellStr($r, $c, $xf, $s) {
        $s = (string)$s;
        if ($s === '') return self::rec(0x0201, pack('vvv', $r, $c, $xf));                 // BLANK
        return self::rec(0x00FD, pack('vvvV', $r, $c, $xf, $this->sstIndex($s)));         // LABELSST
    }
    private static function cellNum($r, $c, $xf, $v) {
        $v = (float)$v;
        if (floor($v) == $v && abs($v) < 536870912) {                                       // RK (int)
            return self::rec(0x027E, pack('vvvV', $r, $c, $xf, ((int)$v << 2) | 2) );
        }
        return self::rec(0x0203, pack('vvv', $r, $c, $xf) . pack('e', $v));               // NUMBER
    }
    private static function row($r, $lastCol, $height) {
        return self::rec(0x0208, pack('vvvvvvV', $r, 0, $lastCol, $height, 0, 0, 0x000F0140));
    }

    /* ---------- workbook ---------- */
    public function build(array $rows) {
        $sheet = $this->sheet($rows);            // sheet truoc de SST day du
        $g = '';
        $g .= self::rec(0x0809, pack('vvvvVV', 0x0600, 0x0005, 0x0DBB, 0x07CC, 0, 6));
        $g .= self::rec(0x00E1, pack('v', 0x04B0));
        $g .= self::rec(0x00C1, pack('v', 0));
        $g .= self::rec(0x00E2, '');
        $g .= self::rec(0x005C, str_pad('None', 112, ' '));
        $g .= self::rec(0x0042, pack('v', 0x04B0));
        $g .= self::rec(0x0161, pack('v', 0));
        $g .= self::rec(0x013D, pack('v', 1));
        $g .= self::rec(0x009C, pack('v', 14));
        foreach (array(0x0019, 0x0012, 0x0063, 0x0013, 0x01AF, 0x01BC, 0x0040, 0x008D) as $t) $g .= self::rec($t, pack('v', 0));
        $g .= self::rec(0x003D, pack('vvvvvvvvv', 0x01E0, 0x005A, 0x3FCF, 0x2A4E, 0x0038, 0, 0, 1, 0x0258));
        $g .= self::rec(0x0022, pack('v', 0));
        $g .= self::rec(0x000E, pack('v', 1));
        $g .= self::rec(0x01B7, pack('v', 0));
        $g .= self::rec(0x00DA, pack('v', 0));
        // FONT: 6 font mac dinh (chi so 0-5, chi so 4 bo qua) + font tuy chinh (chi so 7..12)
        for ($i = 0; $i < 6; $i++) $g .= self::font(200, false, 'Arial');
        $g .= self::font(240, true,  'Times New Roman');   // 7  : tieu de
        $g .= self::font(200, true,  'Times New Roman');   // 8  : dau cot
        $g .= self::font(200, true,  'Times New Roman');   // 9  : dau cot (So tien)
        $g .= self::font(200, false, 'Times New Roman');   // 10 : STT
        $g .= self::font(220, false, 'Calibri');           // 11 : chu
        $g .= self::font(220, false, 'Calibri');           // 12 : so
        $g .= self::rec(0x041E, pack('v', 0x00A4) . self::ustr16('General'));
        // XF: 16 style XF + XF o
        for ($i = 0; $i < 16; $i++) $g .= self::xf(6, 0x20, 0, 0, 0x20C0, true);
        $g .= self::xf(6,  0x20, 0x00000000, 0x00000000, 0x20C0);           // 16 mac dinh
        $g .= self::xf(7,  0x0A, 0x20401111, 0x04002040, 0x20B7);           // 17 tieu de: TNR12 dam, giua/tren, xuong dong, vien 4 canh, nen xam 55
        $g .= self::xf(8,  0x1A, 0x20400111, 0x04000040, 0x2096);           // 18 dau cot: vien trai/phai/tren, nen 22
        $g .= self::xf(9,  0x1A, 0x20400011, 0x04000000, 0x2096);           // 19 dau cot So tien: vien trai/phai
        $g .= self::xf(10, 0x0A, 0x20401111, 0x00002040, 0x20C0);           // 20 STT: giua/tren, vien 4 canh
        $g .= self::xf(11, 0x20, 0x00000000, 0x00000000, 0x20C0);           // 21 chu Calibri 11
        $g .= self::xf(12, 0x20, 0x00000000, 0x00000000, 0x20C0);           // 22 so Calibri 11
        $g .= self::rec(0x0293, pack('vCC', 0x8000, 0, 0xFF));
        $g .= self::rec(0x0092, self::palette());
        $g .= self::rec(0x0160, pack('v', 1));
        // BOUNDSHEET: vi tri BOF cua sheet = len(globals) ; tinh sau
        $bs = self::rec(0x0085, pack('Vv', 0, 0) . self::ustr8('Chi Lo'));
        $sst = $this->sstRecords();
        $eof = self::rec(0x000A, '');
        $pos = strlen($g) + strlen($bs) + strlen($sst) + strlen($eof);
        $bs = self::rec(0x0085, pack('Vv', $pos, 0) . self::ustr8('Chi Lo'));
        $stream = $g . $bs . $sst . $eof . $sheet;
        return self::cfb($stream);
    }

    private function sstRecords() {
        $hdr = pack('VV', $this->sstTotal, count($this->sstList));
        $chunks = array(); $cur = $hdr;
        foreach ($this->sstList as $s) {
            if (strlen($cur) + strlen($s) > 8224 && $cur !== '') { $chunks[] = $cur; $cur = ''; }
            $cur .= $s;
        }
        $chunks[] = $cur;
        $out = '';
        foreach ($chunks as $i => $c) $out .= self::rec($i === 0 ? 0x00FC : 0x003C, $c);
        return $out;
    }

    /* ---------- sheet ---------- */
    private function sheet(array $rows) {
        $s = '';
        $s .= self::rec(0x0809, pack('vvvvVV', 0x0600, 0x0010, 0x0DBB, 0x07CC, 0, 6));
        $s .= self::rec(0x000D, pack('v', 1));
        $s .= self::rec(0x000C, pack('v', 100));
        $s .= self::rec(0x000F, pack('v', 1));
        $s .= self::rec(0x0011, pack('v', 0));
        $s .= self::rec(0x0010, pack('e', 0.001));
        $s .= self::rec(0x005F, pack('v', 0));
        $s .= self::rec(0x0080, pack('vvvv', 0, 0, 1, 1));
        $s .= self::rec(0x0225, pack('vv', 0, 255));
        $s .= self::rec(0x0081, pack('v', 0x0C01));
        foreach ($this->widths as $c => $w) $s .= self::rec(0x007D, pack('vvvvvv', $c, $c, $w, 15, 0, 0));
        $n = count($rows);
        $noteRow = 2 + $n + 1;
        $s .= self::rec(0x0200, pack('VVvvv', 0, $noteRow + 1, 0, 7, 0));
        $s .= self::rec(0x002A, pack('v', 0));
        $s .= self::rec(0x002B, pack('v', 0));
        $s .= self::rec(0x0082, pack('v', 1));
        $s .= self::rec(0x001B, pack('v', 0));
        $s .= self::rec(0x001A, pack('v', 0));
        $s .= self::rec(0x0014, self::ustr16('&P'));
        $s .= self::rec(0x0015, self::ustr16('&F'));
        $s .= self::rec(0x0083, pack('v', 1));
        $s .= self::rec(0x0084, pack('v', 0));
        $s .= self::rec(0x0026, pack('e', 0.3));
        $s .= self::rec(0x0027, pack('e', 0.3));
        $s .= self::rec(0x0028, pack('e', 0.61));
        $s .= self::rec(0x0029, pack('e', 0.37));
        $s .= self::rec(0x00A1, pack('vvvvvvvv', 9, 100, 1, 1, 1, 0x0083, 300, 300) . pack('ee', 0.1, 0.1) . pack('v', 1));
        // xlwt: SETUP = paper 9(A4), scale 100, start page 1, fitW 1, fitH 1, opts 0x83, resH 300, resV 300, header 0.1, footer 0.1, copies 1
        foreach (array(0x0012, 0x00DD, 0x0019, 0x0063, 0x0013) as $t) $s .= self::rec($t, pack('v', 0));

        // dong 1: TIEU DE gop A1:G1
        $s .= self::row(0, 7, 320);
        $s .= $this->cellStr(0, 0, self::XF_TITLE, 'TIÊU ĐỀ');
        $s .= self::rec(0x00BE, pack('vv', 0, 1) . str_repeat(pack('v', self::XF_TITLE), 6) . pack('v', 6));   // MULBLANK
        // dong 2: dau cot
        $s .= self::row(1, 7, 840);
        foreach ($this->head as $c => $h) $s .= $this->cellStr(1, $c, $c === 5 ? self::XF_HEAD6 : self::XF_HEAD, $h);
        // du lieu
        $r = 2;
        foreach ($rows as $i => $row) {
            $row = array_values($row);
            while (count($row) < 7) $row[] = '';
            $s .= self::row($r, 7, 300);
            $s .= self::cellNum($r, 0, self::XF_STT, $i + 1);
            $s .= $this->cellStr($r, 1, self::XF_TXT, $row[1]);
            $s .= $this->cellStr($r, 2, self::XF_TXT, $row[2]);
            $s .= $this->cellStr($r, 3, self::XF_TXT, $row[3]);
            $s .= $this->cellStr($r, 4, self::XF_TXT, $row[4]);
            $amt = $row[5];
            if (is_numeric($amt)) $s .= self::cellNum($r, 5, self::XF_NUM, $amt);
            else $s .= $this->cellStr($r, 5, self::XF_TXT, $amt);
            $s .= $this->cellStr($r, 6, self::XF_TXT, $row[6]);
            $r++;
        }
        // 1 dong trong + dong luu y
        $s .= self::row($noteRow, 1, 300);
        $s .= $this->cellStr($noteRow, 0, self::XF_TXT, self::NOTE);
        $s .= self::rec(0x00E5, pack('vvvvv', 1, 0, 0, 0, 6));
        $s .= self::rec(0x023E, pack('vvvVvvvv', 0x02B6, 0, 0, 0x40, 0, 0, 0, 0));
        $s .= self::rec(0x000A, '');
        return $s;
    }

    private static function palette() {
        // 56 mau chuan Excel, mau 55 (chi so 0x37) doi thanh (150,150,150) nhu file mau
        $rgb = array(
            '000000','ffffff','ff0000','00ff00','0000ff','ffff00','ff00ff','00ffff',
            '800000','008000','000080','808000','800080','008080','c0c0c0','808080',
            '9999ff','993366','ffffcc','ccffff','660066','ff8080','0066cc','ccccff',
            '000080','ff00ff','ffff00','00ffff','800080','800000','008080','0000ff',
            '00ccff','ccffff','ccffcc','ffff99','99ccff','ff99cc','cc99ff','ffcc99',
            '3366ff','33cccc','99cc00','ffcc00','ff9900','ff6600','666699','969696',
            '003366','339966','003300','333300','993300','993366','333399','333333',
        );
        $out = pack('v', 56);
        foreach ($rgb as $c) $out .= chr(hexdec(substr($c, 0, 2))) . chr(hexdec(substr($c, 2, 2))) . chr(hexdec(substr($c, 4, 2))) . "\x00";
        return $out;
    }

    /* ---------- CFB (OLE2) container: 1 stream "Workbook", sector 512, khong dung mini stream (dem stream >= 4096) ---------- */
    private static function cfb($stream) {
        $len = strlen($stream);
        if ($len < 4096) { $stream .= str_repeat("\x00", 4096 - $len); $len = 4096; }
        if ($len % 512) { $stream .= str_repeat("\x00", 512 - $len % 512); $len = strlen($stream); }
        $nS = intdiv($len, 512);
        $nFat = 1;
        while (($nS + $nFat + 1) > $nFat * 128) $nFat++;
        $dirSec = $nS + $nFat;
        // FAT
        $fat = array();
        for ($i = 0; $i < $nS - 1; $i++) $fat[] = $i + 1;
        $fat[] = 0xFFFFFFFE;                                  // ket thuc chuoi stream
        for ($i = 0; $i < $nFat; $i++) $fat[] = 0xFFFFFFFD;   // FAT sector
        $fat[] = 0xFFFFFFFE;                                  // directory
        $fatBin = '';
        foreach ($fat as $v) $fatBin .= pack('V', $v);
        $fatBin = str_pad($fatBin, $nFat * 512, "\xFF\xFF\xFF\xFF");
        // Directory
        $dir  = self::dirEntry('Root Entry', 5, 0xFFFFFFFF, 0xFFFFFFFF, 1, 0xFFFFFFFE, 0);
        $dir .= self::dirEntry('Workbook',   2, 0xFFFFFFFF, 0xFFFFFFFF, 0xFFFFFFFF, 0, $len);
        $dir .= self::dirEntry('', 0, 0xFFFFFFFF, 0xFFFFFFFF, 0xFFFFFFFF, 0xFFFFFFFE, 0);
        $dir .= self::dirEntry('', 0, 0xFFFFFFFF, 0xFFFFFFFF, 0xFFFFFFFF, 0xFFFFFFFE, 0);
        // Header
        $h  = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\x00", 16);
        $h .= pack('vvvvv', 0x003E, 0x0003, 0xFFFE, 0x0009, 0x0006) . str_repeat("\x00", 6);
        $h .= pack('VVVVVVVVV', 0, $nFat, $dirSec, 0, 0x1000, 0xFFFFFFFE, 0, 0xFFFFFFFE, 0);
        for ($i = 0; $i < 109; $i++) $h .= pack('V', $i < $nFat ? $nS + $i : 0xFFFFFFFF);
        return $h . $stream . $fatBin . $dir;
    }
    private static function dirEntry($name, $type, $left, $right, $child, $start, $size) {
        $u = $name === '' ? '' : self::utf16le($name) . "\x00\x00";
        $e  = str_pad($u, 64, "\x00");
        $e .= pack('v', strlen($u));
        $e .= chr($type) . chr(1);
        $e .= pack('VVV', $left, $right, $child);
        $e .= str_repeat("\x00", 16) . pack('V', 0);
        $e .= str_repeat("\x00", 16);
        $e .= pack('VVV', $start, $size, 0);
        return $e;
    }
}
