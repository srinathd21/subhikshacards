<?php
/**
 * includes/whatsapp-api.php
 * Common WhatsApp API file for Subhiksha Cards ERP
 *
 * Use this file in any module:
 * require_once __DIR__ . '/includes/whatsapp-api.php';
 *
 * Example:
 * $wa = subhiksha_send_whatsapp($conn, [
 *     'mobile' => $customerMobile,
 *     'template_key' => 'enquiry_completed',
 *     'variables' => [
 *         'customer_name' => $customerName,
 *         'enquiry_no' => $enquiryNo
 *     ],
 *     'related_module' => 'Enquiries',
 *     'related_id' => $enquiryId,
 *     'customer_id' => $customerId
 * ]);
 */

if (!function_exists('subhiksha_wa_table_exists')) {
    function subhiksha_wa_table_exists(mysqli $conn, string $table): bool
    {
        try {
            $table = $conn->real_escape_string($table);
            $res = $conn->query("SHOW TABLES LIKE '{$table}'");
            $ok = $res && $res->num_rows > 0;
            if ($res) $res->free();
            return $ok;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('subhiksha_wa_column_exists')) {
    function subhiksha_wa_column_exists(mysqli $conn, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $table = $conn->real_escape_string($table);
            $column = $conn->real_escape_string($column);
            $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
            $ok = $res && $res->num_rows > 0;
            if ($res) $res->free();
            $cache[$key] = $ok;
            return $ok;
        } catch (Throwable $e) {
            $cache[$key] = false;
            return false;
        }
    }
}

if (!function_exists('subhiksha_wa_setting')) {
    function subhiksha_wa_setting(mysqli $conn, string $key, string $default = ''): string
    {
        try {
            if (!subhiksha_wa_table_exists($conn, 'system_settings')) {
                return $default;
            }

            $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            return $row ? (string)$row['setting_value'] : $default;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('subhiksha_wa_normalize_mobile')) {
    function subhiksha_wa_normalize_mobile(string $mobile, string $countryCode = '91'): string
    {
        $mobile = preg_replace('/\D+/', '', $mobile);

        if ($mobile === '') {
            return '';
        }

        if (strlen($mobile) === 10) {
            return $countryCode . $mobile;
        }

        if (strlen($mobile) === 12 && str_starts_with($mobile, $countryCode)) {
            return $mobile;
        }

        return $mobile;
    }
}

if (!function_exists('subhiksha_wa_render_template')) {
    function subhiksha_wa_render_template(string $message, array $variables = []): string
    {
        foreach ($variables as $key => $value) {
            $key = trim((string)$key);
            $value = (string)$value;

            $message = str_replace('{{' . $key . '}}', $value, $message);
            $message = str_replace('{' . $key . '}', $value, $message);
        }

        return $message;
    }
}

if (!function_exists('subhiksha_wa_get_template')) {
    function subhiksha_wa_get_template(mysqli $conn, string $templateKey): ?array
    {
        try {
            if (!subhiksha_wa_table_exists($conn, 'whatsapp_templates')) {
                return null;
            }

            $stmt = $conn->prepare("
                SELECT id, template_key, template_name, message_body
                FROM whatsapp_templates
                WHERE template_key = ?
                  AND is_active = 1
                LIMIT 1
            ");
            $stmt->bind_param('s', $templateKey);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('subhiksha_wa_log')) {
    function subhiksha_wa_log(mysqli $conn, array $data): int
    {
        try {
            if (!subhiksha_wa_table_exists($conn, 'whatsapp_logs')) {
                return 0;
            }

            $templateId = !empty($data['template_id']) ? (int)$data['template_id'] : null;
            $relatedModule = (string)($data['related_module'] ?? '');
            $relatedId = !empty($data['related_id']) ? (int)$data['related_id'] : null;
            $customerId = !empty($data['customer_id']) ? (int)$data['customer_id'] : null;
            $jobCardId = !empty($data['job_card_id']) ? (int)$data['job_card_id'] : null;
            $mobile = (string)($data['mobile'] ?? '');
            $messageBody = (string)($data['message_body'] ?? '');
            $status = (string)($data['status'] ?? 'pending');
            $providerResponse = (string)($data['provider_response'] ?? '');
            $sentBy = !empty($data['sent_by']) ? (int)$data['sent_by'] : null;
            $sentAt = !empty($data['sent_at']) ? (string)$data['sent_at'] : null;

            $stmt = $conn->prepare("
                INSERT INTO whatsapp_logs
                    (
                        template_id,
                        related_module,
                        related_id,
                        customer_id,
                        job_card_id,
                        mobile,
                        message_body,
                        status,
                        provider_response,
                        sent_by,
                        sent_at,
                        created_at
                    )
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param(
                'isiiissssis',
                $templateId,
                $relatedModule,
                $relatedId,
                $customerId,
                $jobCardId,
                $mobile,
                $messageBody,
                $status,
                $providerResponse,
                $sentBy,
                $sentAt
            );
            $stmt->execute();
            $id = (int)$stmt->insert_id;
            $stmt->close();

            return $id;
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('subhiksha_send_whatsapp')) {
    function subhiksha_send_whatsapp(mysqli $conn, array $params): array
    {
        $enabled = subhiksha_wa_setting($conn, 'whatsapp_enabled', '0');

        $mobile = subhiksha_wa_normalize_mobile((string)($params['mobile'] ?? ''));
        $templateId = null;
        $message = trim((string)($params['message'] ?? ''));

        if ($mobile === '') {
            return [
                'success' => false,
                'message' => 'Mobile number is required.',
                'log_id' => 0,
                'response' => ''
            ];
        }

        if ($message === '' && !empty($params['template_key'])) {
            $template = subhiksha_wa_get_template($conn, (string)$params['template_key']);

            if ($template) {
                $templateId = (int)$template['id'];
                $message = subhiksha_wa_render_template(
                    (string)$template['message_body'],
                    (array)($params['variables'] ?? [])
                );
            }
        }

        if ($message === '') {
            return [
                'success' => false,
                'message' => 'Message or valid template_key is required.',
                'log_id' => 0,
                'response' => ''
            ];
        }

        $sentBy = !empty($params['sent_by'])
            ? (int)$params['sent_by']
            : (int)($_SESSION['user_id'] ?? 0);

        if ($enabled !== '1') {
            $response = 'WhatsApp disabled. Set whatsapp_enabled = 1 in system_settings.';

            $logId = subhiksha_wa_log($conn, [
                'template_id' => $templateId,
                'related_module' => $params['related_module'] ?? '',
                'related_id' => $params['related_id'] ?? null,
                'customer_id' => $params['customer_id'] ?? null,
                'job_card_id' => $params['job_card_id'] ?? null,
                'mobile' => $mobile,
                'message_body' => $message,
                'status' => 'failed',
                'provider_response' => $response,
                'sent_by' => $sentBy,
                'sent_at' => null
            ]);

            return [
                'success' => false,
                'message' => 'WhatsApp integration disabled.',
                'log_id' => $logId,
                'response' => $response
            ];
        }

        /*
         * These values are stored in system_settings table.
         * Do not hardcode secret key in pages.
         */
        $apiUrl = subhiksha_wa_setting($conn, 'watzup_api_url', '');
        $apiToken = subhiksha_wa_setting($conn, 'watzup_api_token', '');
        $senderId = subhiksha_wa_setting($conn, 'watzup_sender_id', '');

        $method = strtoupper(subhiksha_wa_setting($conn, 'watzup_api_method', 'POST'));
        $payloadFormat = strtolower(subhiksha_wa_setting($conn, 'watzup_payload_format', 'form'));

        /*
         * Provider parameter names.
         * Change these in DB only if provider uses different names.
         */
        $mobileParam = subhiksha_wa_setting($conn, 'watzup_mobile_param', 'recipient');
        $messageParam = subhiksha_wa_setting($conn, 'watzup_message_param', 'message');
        $tokenParam = subhiksha_wa_setting($conn, 'watzup_token_param', 'secret');
        $senderParam = subhiksha_wa_setting($conn, 'watzup_sender_param', 'account');

        if ($apiUrl === '' || $apiToken === '' || $senderId === '') {
            $response = 'WhatsApp API URL / Secret Key / Unique ID missing in system_settings.';

            $logId = subhiksha_wa_log($conn, [
                'template_id' => $templateId,
                'related_module' => $params['related_module'] ?? '',
                'related_id' => $params['related_id'] ?? null,
                'customer_id' => $params['customer_id'] ?? null,
                'job_card_id' => $params['job_card_id'] ?? null,
                'mobile' => $mobile,
                'message_body' => $message,
                'status' => 'failed',
                'provider_response' => $response,
                'sent_by' => $sentBy,
                'sent_at' => null
            ]);

            return [
                'success' => false,
                'message' => 'WhatsApp API settings missing.',
                'log_id' => $logId,
                'response' => $response
            ];
        }

        $payload = [
            $mobileParam => $mobile,
            $messageParam => $message,
            $tokenParam => $apiToken,
            $senderParam => $senderId
        ];

        if (!empty($params['extra_payload']) && is_array($params['extra_payload'])) {
            $payload = array_merge($payload, $params['extra_payload']);
        }

        $httpCode = 0;
        $rawResponse = '';
        $success = false;

        try {
            $ch = curl_init();

            if ($method === 'GET') {
                $url = $apiUrl . (str_contains($apiUrl, '?') ? '&' : '?') . http_build_query($payload);
                curl_setopt($ch, CURLOPT_URL, $url);
            } else {
                curl_setopt($ch, CURLOPT_URL, $apiUrl);
                curl_setopt($ch, CURLOPT_POST, true);

                if ($payloadFormat === 'json') {
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                } else {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
                }
            }

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, (int)($params['timeout'] ?? 30));
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);

            /*
             * Keep SSL verify true on live.
             * For localhost SSL issue only, pass ssl_verify => false.
             */
            $sslVerify = array_key_exists('ssl_verify', $params) ? (bool)$params['ssl_verify'] : true;
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $sslVerify);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $sslVerify ? 2 : 0);

            $rawResponse = (string)curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if (curl_errno($ch)) {
                $rawResponse = curl_error($ch);
            }

            curl_close($ch);

            $success = ($httpCode >= 200 && $httpCode < 300);

            $decoded = json_decode($rawResponse, true);
            if (is_array($decoded)) {
                if (isset($decoded['success'])) {
                    $success = (bool)$decoded['success'];
                } elseif (isset($decoded['status']) && strtolower((string)$decoded['status']) === 'success') {
                    $success = true;
                }
            }
        } catch (Throwable $e) {
            $rawResponse = $e->getMessage();
            $httpCode = 0;
            $success = false;
        }

        $logId = subhiksha_wa_log($conn, [
            'template_id' => $templateId,
            'related_module' => $params['related_module'] ?? '',
            'related_id' => $params['related_id'] ?? null,
            'customer_id' => $params['customer_id'] ?? null,
            'job_card_id' => $params['job_card_id'] ?? null,
            'mobile' => $mobile,
            'message_body' => $message,
            'status' => $success ? 'sent' : 'failed',
            'provider_response' => json_encode([
                'http_code' => $httpCode,
                'response' => $rawResponse
            ]),
            'sent_by' => $sentBy,
            'sent_at' => $success ? date('Y-m-d H:i:s') : null
        ]);

        return [
            'success' => $success,
            'message' => $success ? 'WhatsApp sent successfully.' : 'WhatsApp sending failed.',
            'log_id' => $logId,
            'http_code' => $httpCode,
            'mobile' => $mobile,
            'body' => $message,
            'response' => $rawResponse
        ];
    }
}