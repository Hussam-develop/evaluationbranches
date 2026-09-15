<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

function respond(
    bool $success,
    string $message,
    int $status = 200,
    array $extra = []
): never {
    http_response_code($status);

    echo json_encode(
        array_merge(
            [
                'success' => $success,
                'message' => $message,
            ],
            $extra
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

try {

    // يجب أن يكون الطلب POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(
            false,
            'طريقة الطلب غير مسموحة. يجب إرسال الطلب بواسطة POST.',
            405
        );
    }

    // رقم التقرير
    $submissionId = trim(
        (string) ($_POST['submission_id'] ?? '')
    );

    if ($submissionId === '') {
        respond(
            false,
            'رقم التقرير غير موجود.',
            400
        );
    }

    // السماح فقط بأرقام/حروف و - _
    if (!preg_match('/^[A-Za-z0-9_-]{8,100}$/', $submissionId)) {
        respond(
            false,
            'رقم التقرير غير صالح.',
            400
        );
    }

    /*
     * ضع هنا Bot Token الجديد.
     *
     * مهم جداً:
     * التوكن الذي أرسلته في رسالتك أصبح مكشوفاً،
     * لذلك يجب إلغاؤه وإنشاء Token جديد من BotFather.
     */
    $botToken = '8822902676:AAFSnSvNGAhRPtuluTfal12aiSd9Mgb6ggA';
    //  $chatId   = '2222222222';
    $chatIds = [
        '1026472527',
        '1320534733',
    ];

    if ($botToken === '' || $botToken === 'PUT_NEW_BOT_TOKEN_HERE') {
        respond(
            false,
            'لم يتم إعداد Bot Token الجديد في ملف PHP.',
            500
        );
    }

    if (empty($chatIds)) {
        respond(
            false,
            'لم يتم إعداد Chat IDs في ملف PHP.',
            500
        );
    }

    if (!extension_loaded('curl')) {
        respond(
            false,
            'إضافة cURL غير مفعّلة في PHP على السيرفر.',
            500
        );
    }

    if (!class_exists('CURLFile')) {
        respond(
            false,
            'CURLFile غير متاحة في PHP على السيرفر.',
            500
        );
    }

    if (!isset($_FILES['photo'])) {
        respond(
            false,
            'لم يتم استلام صورة التقرير من الموقع.',
            400
        );
    }

    $file = $_FILES['photo'];

    if (
        !isset($file['error']) ||
        (int) $file['error'] !== UPLOAD_ERR_OK
    ) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE =>
                'الصورة أكبر من الحد المسموح به في إعدادات PHP.',

            UPLOAD_ERR_FORM_SIZE =>
                'الصورة أكبر من الحد المسموح به في النموذج.',

            UPLOAD_ERR_PARTIAL =>
                'تم رفع الصورة بشكل جزئي فقط.',

            UPLOAD_ERR_NO_FILE =>
                'لم يتم رفع أي صورة.',

            UPLOAD_ERR_NO_TMP_DIR =>
                'مجلد الملفات المؤقتة غير متاح على السيرفر.',

            UPLOAD_ERR_CANT_WRITE =>
                'تعذر كتابة الصورة على السيرفر.',

            UPLOAD_ERR_EXTENSION =>
                'إضافة PHP أوقفت رفع الصورة.',
        ];

        $code = (int) ($file['error'] ?? -1);

        respond(
            false,
            $uploadErrors[$code]
                ?? ('حدث خطأ أثناء رفع الصورة. رمز الخطأ: ' . $code),
            400
        );
    }

    if (
        !isset($file['tmp_name']) ||
        !is_uploaded_file($file['tmp_name'])
    ) {
        respond(
            false,
            'ملف الصورة غير صالح أو لم يصل من المتصفح.',
            400
        );
    }

    if ((int) $file['size'] > 10 * 1024 * 1024) {
        respond(
            false,
            'حجم الصورة أكبر من 10MB.',
            400
        );
    }

    if (!class_exists('finfo')) {
        respond(
            false,
            'إضافة Fileinfo غير مفعّلة في PHP على السيرفر.',
            500
        );
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    if ($mime !== 'image/png') {
        respond(
            false,
            'نوع الصورة غير مسموح. يجب أن تكون PNG.',
            400
        );
    }

    $telegramUrl =
        'https://api.telegram.org/bot' .
        $botToken .
        '/sendPhoto';

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
                basename(
                    (string) (
                        $file['name']
                        ?? 'branch-report.png'
                    )
                )
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
        $httpCode = (int) curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

        // لا تستخدم curl_close في PHP 8.5+
        unset($ch);

        if ($response === false) {
            $failed[] = [
                'chat_id' => $chatId,
                'message' =>
                    'تعذر الاتصال بـ Telegram: ' .
                    ($curlError ?: 'خطأ cURL غير معروف.')
            ];
            continue;
        }

        $result = json_decode(
            (string) $response,
            true
        );

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
                    ? (string) (
                        $result['description']
                        ?? 'رفض Telegram عملية الإرسال.'
                    )
                    : 'استجابة غير صالحة من Telegram.'
            ];
        }
    }

    /*
     * نجح الإرسال للجميع
     */
    if (
        count($sent) === count($chatIds) &&
        count($sent) > 0
    ) {
        respond(
            true,
            'تم إرسال الصورة بنجاح إلى جميع المستلمين.',
            200,
            [
                'submission_id' => $submissionId,
                'sent_count' => count($sent),
            ]
        );
    }

    /*
     * نجح لبعض المستلمين
     */
    if (count($sent) > 0) {
        respond(
            true,
            'تم إرسال الصورة إلى بعض المستلمين.',
            200,
            [
                'submission_id' => $submissionId,
                'sent_count' => count($sent),
                'failed' => $failed,
            ]
        );
    }

    /*
     * فشل الجميع
     */
    respond(
        false,
        'تعذر إرسال الصورة إلى أي من المستلمين.',
        502,
        [
            'submission_id' => $submissionId,
            'failed' => $failed,
        ]
    );

} catch (Throwable $e) {

    respond(
        false,
        'خطأ في الخادم: ' . $e->getMessage(),
        500
    );
}