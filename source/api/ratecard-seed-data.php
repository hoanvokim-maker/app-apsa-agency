<?php
// Dữ liệu gốc từ APSA_RateCard_Master_2026_EN-VN.xlsx — dùng để seed bảng ratecard_items lần đầu tiên.
// KHÔNG chỉnh sửa file này để sửa giá — sửa trực tiếp trên trang ratecard.html, dữ liệu nằm trong DB.
// Dữ liệu thực tế nằm trong ratecard-seed.tsv (định dạng TSV, mỗi dòng 1 hạng mục) để nhẹ và dễ bảo trì.

$tsvFile = __DIR__ . '/ratecard-seed.tsv';
if (!is_file($tsvFile)) return [];

$keys = [
    'sheet_key', 'cat_code', 'cat_en', 'cat_vn', 'no_label',
    'item_en', 'item_vn', 'desc_en', 'desc_vn',
    'unit_en', 'unit_vn', 'basic', 'standard', 'premium',
    'notes_en', 'notes_vn', 'sort_order',
];

$rows = [];
$lines = file($tsvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    $cols = explode("\t", $line);
    $cols = array_pad($cols, count($keys), '');
    $row = array_combine($keys, array_slice($cols, 0, count($keys)));
    $row['basic']      = (float)$row['basic'];
    $row['standard']   = (float)$row['standard'];
    $row['premium']    = (float)$row['premium'];
    $row['sort_order'] = (int)$row['sort_order'];
    $rows[] = $row;
}
return $rows;
