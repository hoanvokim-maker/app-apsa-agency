<?php
/**
 * APSA - Lich nghi le chinh thuc cua Viet Nam.
 *
 * Moi nam co 11 ngay nghi le huong nguyen luong theo Bo luat Lao dong 2019
 * (sua doi 2024): Tet Duong lich 1, Tet Am lich 5, Gio To Hung Vuong 1,
 * 30/4 1, 1/5 1, Quoc khanh 2. Ngoai ra Chinh phu cong bo them ngay nghi bu
 * va ngay hoan doi cho tung nam.
 *
 * Muon them nam moi: chi can them mot khoi vao vnh_data().
 * Moi dong: array('Y-m-d', 'Ten ngay le', 'law|bu|company', 'Ghi chu')
 *   law     - ngay nghi le theo luat
 *   bu      - ngay nghi bu / hoan doi do Chinh phu cong bo
 *   company - ngay nghi rieng cua cong ty (khong nap tu day)
 */

function vnh_data()
{
    return array(

        /* --- 2026: da co quyet dinh chinh thuc --- */
        2026 => array(
            array('2026-01-01', 'Tết Dương lịch', 'law', ''),
            array('2026-02-16', 'Tết Bính Ngọ - 29 tháng Chạp', 'law', 'Kỳ nghỉ Tết 14/2 đến 22/2/2026, 9 ngày'),
            array('2026-02-17', 'Mùng 1 Tết Bính Ngọ', 'law', ''),
            array('2026-02-18', 'Mùng 2 Tết Bính Ngọ', 'law', ''),
            array('2026-02-19', 'Mùng 3 Tết Bính Ngọ', 'law', ''),
            array('2026-02-20', 'Mùng 4 Tết Bính Ngọ', 'law', ''),
            array('2026-04-26', 'Giỗ Tổ Hùng Vương (10/3 âm lịch)', 'law', ''),
            array('2026-04-27', 'Nghỉ bù Giỗ Tổ Hùng Vương', 'bu', 'Do 10/3 âm lịch rơi vào Chủ nhật'),
            array('2026-04-30', 'Ngày Giải phóng miền Nam 30/4', 'law', ''),
            array('2026-05-01', 'Ngày Quốc tế Lao động 1/5', 'law', ''),
            array('2026-08-31', 'Hoán đổi nghỉ lễ Quốc khánh', 'bu', 'Làm bù thứ Bảy 22/8/2026'),
            array('2026-09-01', 'Quốc khánh - ngày liền kề 1/9', 'law', ''),
            array('2026-09-02', 'Quốc khánh 2/9', 'law', 'Kỳ nghỉ 29/8 đến 2/9/2026, 5 ngày'),
        ),

        /* --- 2027: theo phuong an Chinh phu de xuat, chua co quyet dinh cuoi --- */
        2027 => array(
            array('2027-01-01', 'Tết Dương lịch', 'law', ''),
            array('2027-02-04', 'Nghỉ bù Tết Đinh Mùi', 'bu', 'Dự kiến - kỳ nghỉ Tết 4/2 đến 10/2/2027, 7 ngày'),
            array('2027-02-05', 'Tết Đinh Mùi - ngày cuối năm Bính Ngọ', 'law', 'Dự kiến'),
            array('2027-02-06', 'Mùng 1 Tết Đinh Mùi', 'law', 'Dự kiến'),
            array('2027-02-07', 'Mùng 2 Tết Đinh Mùi', 'law', 'Dự kiến'),
            array('2027-02-08', 'Mùng 3 Tết Đinh Mùi', 'law', 'Dự kiến'),
            array('2027-02-09', 'Mùng 4 Tết Đinh Mùi', 'law', 'Dự kiến'),
            array('2027-02-10', 'Nghỉ bù Tết Đinh Mùi', 'bu', 'Dự kiến - bù mùng 1 và mùng 2 rơi vào cuối tuần'),
            array('2027-04-16', 'Giỗ Tổ Hùng Vương (10/3 âm lịch)', 'law', ''),
            array('2027-04-30', 'Ngày Giải phóng miền Nam 30/4', 'law', ''),
            array('2027-05-01', 'Ngày Quốc tế Lao động 1/5', 'law', ''),
            array('2027-05-03', 'Nghỉ bù 1/5', 'bu', 'Dự kiến - 1/5/2027 rơi vào thứ Bảy'),
            array('2027-09-02', 'Quốc khánh 2/9', 'law', 'Dự kiến - kỳ nghỉ 2/9 đến 5/9/2027, 4 ngày'),
            array('2027-09-03', 'Quốc khánh - ngày liền kề 3/9', 'law', 'Dự kiến'),
        ),

    );
}

/** Danh sach nam co san, moi nhat truoc. */
function vnh_years()
{
    $y = array_map('intval', array_keys(vnh_data()));
    rsort($y);
    return $y;
}

/** Danh sach ngay le cua 1 nam. */
function vnh_year($y)
{
    $d = vnh_data();
    $y = (int) $y;
    return isset($d[$y]) ? $d[$y] : array();
}
