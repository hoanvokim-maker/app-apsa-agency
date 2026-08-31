<?php
/**
 * ai-config.example.php
 *
 * 1. Copy file nay thanh  api/ai-config.php
 * 2. Dien 2 khoa API vao ben duoi
 * 3. chmod 600 api/ai-config.php
 *
 * File api/ai-config.php KHONG duoc commit len git (da co trong .gitignore).
 * Khong dan khoa API vao chat hay chup man hinh gui cho ai.
 *
 * Lay khoa o dau:
 *   - Google Gemini : https://aistudio.google.com/apikey  (phai bat thanh toan,
 *                     model tao anh khong co goi mien phi)
 *   - fal.ai        : https://fal.ai/dashboard/keys
 */

return array(

    /* --- Google Gemini: duong chinh --- */
    'gemini_key'   => '',

    /* Muc Nhanh - dung cho hau het su kien.
       ~0,067 USD/anh 1K, khoang 14 giay. */
    'gemini_model'     => 'gemini-3.1-flash-image',   /* Nano Banana 2 */

    /* Muc Cao - dep hon va viet CHU trong anh dung hon nhieu.
       Dat gap doi (~0,134 USD/anh) va cham hon. Chon theo tung su kien,
       hoac danh dau rieng cho nhung prompt co chu trong anh. */
    'gemini_model_pro' => 'gemini-3-pro-image',       /* Nano Banana Pro */

    /* --- fal.ai: duong du phong khi Google nghen hoac loi --- */
    'fal_key'       => '',
    'fal_model'     => 'fal-ai/nano-banana/edit',

    /* fal cung chay dung Nano Banana Pro, nen day KHONG phai la ha chat luong -
       cung model, khac nha cung cap. De rong neu muon muc Cao bao loi thay vi
       chuyen nha cung cap. */
    'fal_model_pro' => 'fal-ai/nano-banana-pro/edit',

    /* --- Dieu phoi tai --- */

    /* So anh duoc tao cung luc. Tang len neu server khoe va han muc API cao.
       8 la muc an toan cho 50-100 khach: nguoi thu 9 tro di xep hang, moi anh
       xong trong khoang 10-20 giay nen hang doi tan rat nhanh. */
    'max_inflight' => 8,

    /* Giay. Qua nguong nay coi nhu that bai va thu lai. */
    'timeout'      => 90,

    /* So lan thu lai moi anh (tinh ca lan dau). */
    'max_attempts' => 3,
);
