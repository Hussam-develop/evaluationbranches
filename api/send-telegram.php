<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(bool $success, string $message, int $status = 200, array $extra = []): never
{
    http_response_code($status);
    echo json_encode(
        array_merge([
            'success' => $success,
            'message' => $message,
        ], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

try {
    // ضع بيانات البوت هنا على السيرفر فقط.
    // لا تضع Bot Token داخل HTML أو JavaScript.
    // ضع بيانات البوت هنا. لا تضع هذا الملف في مستودع عام.
    $botToken = '8822902676:AAFSnSvNGAhRPtuluTfal12aiSd9Mgb6ggA';
    //  $chatId   = '2222222222';
    $chatIds = [
        '1026472527',
        '1320534733',
    ];
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(false, 'طريقة الطلب غير مسموحة. يجب إرسال الطلب بواسطة POST.', 405);
    }

    /*    if ($botToken === '' || $chatId === '') {
           respond(false, 'لم يتم إعداد Bot Token و Chat ID في ملف PHP.', 500);
       }
    */

    if ($botToken === '' || empty($chatIds)) {
        respond(false, 'لم يتم إعداد Bot Token أو Chat IDs في ملف PHP.', 500);
    }

    if (!extension_loaded('curl')) {
        respond(false, 'إضافة cURL غير مفعّلة في PHP على السيرفر. فعّل PHP cURL ثم أعد المحاولة.', 500);
    }

    if (!class_exists('CURLFile')) {
        respond(false, 'CURLFile غير متاحة في PHP على السيرفر. تحقق من إصدار PHP وإضافة cURL.', 500);
    }

    if (!isset($_FILES['photo'])) {
        respond(false, 'لم يتم استلام صورة التقرير من الموقع.', 400);
    }

    $file = $_FILES['photo'];

    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE => 'الصورة أكبر من الحد المسموح به في إعدادات PHP.',
            UPLOAD_ERR_FORM_SIZE => 'الصورة أكبر من الحد المسموح به في النموذج.',
            UPLOAD_ERR_PARTIAL => 'تم رفع الصورة بشكل جزئي فقط.',
            UPLOAD_ERR_NO_FILE => 'لم يتم رفع أي صورة.',
            UPLOAD_ERR_NO_TMP_DIR => 'مجلد الملفات المؤقتة غير متاح على السيرفر.',
            UPLOAD_ERR_CANT_WRITE => 'تعذر كتابة الصورة على السيرفر.',
            UPLOAD_ERR_EXTENSION => 'إضافة PHP أوقفت رفع الصورة.',
        ];

        $code = (int) ($file['error'] ?? -1);
        respond(false, $uploadErrors[$code] ?? ('حدث خطأ أثناء رفع الصورة. رمز الخطأ: ' . $code), 400);
    }

    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        respond(false, 'ملف الصورة غير صالح أو لم يصل من المتصفح.', 400);
    }

    if ((int) $file['size'] > 10 * 1024 * 1024) {
        respond(false, 'حجم الصورة أكبر من 10MB.', 400);
    }

    if (!class_exists('finfo')) {
        respond(false, 'إضافة Fileinfo غير مفعّلة في PHP على السيرفر.', 500);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    if ($mime !== 'image/png') {
        respond(false, 'نوع الصورة غير مسموح. يجب أن تكون PNG. النوع المستلم: ' . ($mime ?: 'غير معروف'), 400);
    }

    $telegramUrl = 'https://api.telegram.org/bot' . $botToken . '/sendPhoto';


    $sent = [];
    $failed = [];

    foreach ($chatIds as $chatId) {

        $chatId = trim((string) $chatId);

        if ($chatId === '') {
            continue;
        }

        $postFields = [
            'chat_id' => $chatId,
            'photo' => new CURLFile(
                $file['tmp_name'],
                'image/png',
                basename((string) ($file['name'] ?? 'branch-report.png'))
            ),
        ];

        $ch = curl_init($telegramUrl);

        if ($ch === false) {
            $failed[] = [
                'chat_id' => $chatId,
                'message' => 'تعذر إنشاء اتصال cURL.'
            ];
            continue;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // لا تستخدم curl_close() في PHP 8.5
        unset($ch);

        if ($response === false) {
            $failed[] = [
                'chat_id' => $chatId,
                'message' => 'تعذر الاتصال بـ Telegram: ' .
                    ($curlError ?: 'خطأ cURL غير معروف.')
            ];
            continue;
        }

        $result = json_decode((string) $response, true);

        if (
            $httpCode >= 200 &&
            $httpCode < 300 &&
            is_array($result) &&
            !empty($result['ok'])
        ) {
            $sent[] = $chatId;
        } else {
            $failed[] = [
                'chat_id' => $chatId,
                'message' => is_array($result)
                    ? (string) ($result['description'] ?? 'رفض Telegram عملية الإرسال.')
                    : 'استجابة غير صالحة من Telegram.'
            ];
        }
    }

    if (count($sent) === count($chatIds) && count($sent) > 0) {
        respond(
            true,
            'تم إرسال الصورة بنجاح إلى جميع المستلمين.',
            200,
            [
                'sent_count' => count($sent)
            ]
        );
    }

    if (count($sent) > 0) {
        respond(
            true,
            'تم إرسال الصورة إلى بعض المستلمين.',
            200,
            [
                'sent_count' => count($sent),
                'failed' => $failed
            ]
        );
    }

    respond(
        false,
        'تعذر إرسال الصورة إلى أي من المستلمين.',
        502,
        [
            'failed' => $failed
        ]
    );






    /* 
        $postFields = [
            'chat_id' => $chatId,
            'photo' => new CURLFile(
                $file['tmp_name'],
                'image/png',
                basename((string)($file['name'] ?? 'branch-report.png'))
            ),
        ];

        $ch = curl_init($telegramUrl);

        if ($ch === false) {
            respond(false, 'تعذر إنشاء اتصال cURL على السيرفر.', 500);
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);


        if ($response === false) {
            respond(false, 'تعذر الاتصال بخدمة Telegram من السيرفر: ' . ($curlError ?: 'خطأ cURL غير معروف.'), 502);
        }

        $result = json_decode((string)$response, true);

        if ($httpCode >= 200 && $httpCode < 300 && is_array($result) && !empty($result['ok'])) {
            respond(true, 'تم إرسال الصورة بنجاح إلى Telegram.');
        }

        if (is_array($result)) {
            $telegramMessage = (string)($result['description'] ?? 'رفض Telegram عملية الإرسال.');
            respond(
                false,
                'Telegram: ' . $telegramMessage,
                $httpCode >= 400 ? $httpCode : 400,
                ['telegram_http_code' => $httpCode]
            );
        }

        respond(
            false,
            'استجابة غير صالحة من Telegram. HTTP: ' . $httpCode . ' — ' . substr((string)$response, 0, 500),
            502
        );
     */
} catch (Throwable $e) {
    respond(false, 'خطأ في الخادم: ' . $e->getMessage(), 500);
}
?>