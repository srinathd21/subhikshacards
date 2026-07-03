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

        $templateKey = trim((string)($params['template_key'] ?? ''));

        /*
         * Template-first method:
         * If template_key is given, message body must come from whatsapp_templates.
         * This keeps all module messages editable from DB and avoids hardcoded module text.
         */
        if ($templateKey !== '') {
            $template = subhiksha_wa_get_template($conn, $templateKey);

            if (!$template) {
                $response = 'Active WhatsApp template not found for template_key: ' . $templateKey;
                $logId = subhiksha_wa_log($conn, [
                    'template_id' => null,
                    'related_module' => $params['related_module'] ?? '',
                    'related_id' => $params['related_id'] ?? null,
                    'customer_id' => $params['customer_id'] ?? null,
                    'job_card_id' => $params['job_card_id'] ?? null,
                    'mobile' => $mobile,
                    'message_body' => '',
                    'status' => 'failed',
                    'provider_response' => $response,
                    'sent_by' => !empty($params['sent_by']) ? (int)$params['sent_by'] : (int)($_SESSION['user_id'] ?? 0),
                    'sent_at' => null
                ]);

                return [
                    'success' => false,
                    'message' => 'WhatsApp template missing or inactive.',
                    'template_key' => $templateKey,
                    'log_id' => $logId,
                    'response' => $response
                ];
            }

            $templateId = (int)$template['id'];
            $message = subhiksha_wa_render_template(
                (string)$template['message_body'],
                (array)($params['variables'] ?? [])
            );
        }

        if ($message === '') {
            return [
                'success' => false,
                'message' => 'Message or valid active template_key is required.',
                'template_key' => $templateKey,
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
        $followRedirects = subhiksha_wa_setting($conn, 'watzup_follow_redirects', '0') === '1';

        /*
         * Provider parameter names.
         * Change these in DB only if provider uses different names.
         */
        $mobileParam = subhiksha_wa_setting($conn, 'watzup_mobile_param', 'recipient');
        $messageParam = subhiksha_wa_setting($conn, 'watzup_message_param', 'message');
        $tokenParam = subhiksha_wa_setting($conn, 'watzup_token_param', 'secret');
        $senderParam = subhiksha_wa_setting($conn, 'watzup_sender_param', 'account');
        $typeParam = subhiksha_wa_setting($conn, 'watzup_type_param', 'type');
        $messageType = (string)($params['message_type'] ?? subhiksha_wa_setting($conn, 'watzup_message_type', 'text'));

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

        if ($typeParam !== '' && $messageType !== '') {
            $payload[$typeParam] = $messageType;
        }

        if (!empty($params['extra_payload']) && is_array($params['extra_payload'])) {
            $payload = array_merge($payload, $params['extra_payload']);
        }

        $httpCode = 0;
        $rawResponse = '';
        $success = false;
        $effectiveUrl = '';
        $redirectUrl = '';
        $contentType = '';

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
                } elseif ($payloadFormat === 'multipart') {
                    /* Watzup sample uses CURLOPT_POSTFIELDS as an array. */
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                } else {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
                }
            }

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, (int)($params['timeout'] ?? 30));
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $followRedirects);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 3);

            /*
             * Keep SSL verify true on live.
             * For localhost SSL issue only, pass ssl_verify => false.
             */
            $sslVerify = array_key_exists('ssl_verify', $params) ? (bool)$params['ssl_verify'] : true;
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $sslVerify);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $sslVerify ? 2 : 0);

            $rawResponse = (string)curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $redirectUrl = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

            if (curl_errno($ch)) {
                $rawResponse = curl_error($ch);
            }

            curl_close($ch);

            $success = ($httpCode >= 200 && $httpCode < 300);

            if ($httpCode >= 300 && $httpCode < 400) {
                $success = false;
                if ($rawResponse === '' && $redirectUrl !== '') {
                    $rawResponse = 'HTTP ' . $httpCode . ' redirect to: ' . $redirectUrl . '. Use the exact WhatsApp send API endpoint, not the dashboard/base URL.';
                }
            }

            if (stripos($contentType, 'text/html') !== false || preg_match('/<html|<!doctype/i', $rawResponse)) {
                $success = false;
            }

            $decoded = json_decode($rawResponse, true);
            if (is_array($decoded)) {
                $decodedStatus = strtolower((string)($decoded['status'] ?? ''));
                $decodedMessage = strtolower((string)($decoded['message'] ?? ''));

                if (isset($decoded['success'])) {
                    $success = (bool)$decoded['success'];
                } elseif (in_array($decodedStatus, ['success', 'sent', 'queued', '200'], true)) {
                    $success = true;
                } elseif (isset($decoded['status']) && (int)$decoded['status'] === 200) {
                    $success = true;
                } elseif (str_contains($decodedMessage, 'queued') || str_contains($decodedMessage, 'sent successfully')) {
                    $success = true;
                } elseif (!empty($decoded['data']['messageId']) || !empty($decoded['data']['message_id'])) {
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
                'effective_url' => $effectiveUrl,
                'redirect_url' => $redirectUrl,
                'content_type' => $contentType,
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
            'effective_url' => $effectiveUrl,
            'redirect_url' => $redirectUrl,
            'content_type' => $contentType,
            'response' => $rawResponse
        ];
    }
}


if (!function_exists('subhiksha_send_template_whatsapp')) {
    /**
     * Universal template-based WhatsApp sending method for all modules.
     *
     * Usage:
     * subhiksha_send_template_whatsapp($conn, 'quotation_created', $mobile, [
     *     'customer_name' => $customerName,
     *     'quotation_no' => $quotationNo,
     * ], [
     *     'related_module' => 'Quotations',
     *     'related_id' => $quotationId,
     *     'customer_id' => $customerId
     * ]);
     */
    function subhiksha_send_template_whatsapp(
        mysqli $conn,
        string $templateKey,
        string $mobile,
        array $variables = [],
        array $meta = []
    ): array {
        $params = $meta;

        /* Force template DB method. Do not allow a direct hardcoded message to override template. */
        unset($params['message']);

        $params['mobile'] = $mobile;
        $params['template_key'] = $templateKey;
        $params['variables'] = $variables;

        return subhiksha_send_whatsapp($conn, $params);
    }
}

if (!function_exists('subhiksha_wa_supported_template_keys')) {
    /**
     * Reference list of current Subhiksha WhatsApp template keys.
     * The actual message body is always read from whatsapp_templates table.
     */
    function subhiksha_wa_supported_template_keys(): array
    {
        return [
            'enquiry_completed' => ['module' => 'Enquiries', 'variables' => ['customer_name', 'enquiry_no', 'function_type', 'order_type']],
            'followup_updated' => ['module' => 'Follow-ups', 'variables' => ['customer_name', 'enquiry_no', 'next_followup_date']],
            'quotation_created' => ['module' => 'Quotations', 'variables' => ['customer_name', 'quotation_no', 'function_type', 'number_of_items', 'item_details', 'price', 'final_price']],
            'proforma_created' => ['module' => 'Proforma Bills', 'variables' => ['customer_name', 'proforma_no', 'product_name', 'order_type', 'final_amount', 'advance_amount', 'balance_amount', 'delivery_date']],
            'advance_payment_received' => ['module' => 'Payments', 'variables' => ['customer_name', 'proforma_no', 'paid_amount', 'payment_mode', 'balance_amount']],
            'job_card_created' => ['module' => 'Job Cards', 'variables' => ['customer_name', 'job_card_no', 'product_name', 'order_type', 'current_stage', 'delivery_date']],
            'designing_started' => ['module' => 'Job Tracking', 'variables' => ['customer_name', 'job_card_no']],
            'proofing_ready' => ['module' => 'Job Tracking', 'variables' => ['customer_name', 'job_card_no']],
            'design_approval_required' => ['module' => 'Customer Approvals', 'variables' => ['customer_name', 'job_card_no', 'tracking_link']],
            'design_approved' => ['module' => 'Customer Approvals', 'variables' => ['customer_name', 'job_card_no']],
            'printing_started' => ['module' => 'Job Tracking', 'variables' => ['customer_name', 'job_card_no', 'printing_type', 'current_stage']],
            'lamination_started' => ['module' => 'Job Tracking', 'variables' => ['customer_name', 'job_card_no', 'lamination_type']],
            'ready_for_delivery' => ['module' => 'Dispatch', 'variables' => ['customer_name', 'job_card_no', 'balance_amount']],
            'dispatch_completed' => ['module' => 'Dispatch', 'variables' => ['customer_name', 'job_card_no', 'dispatch_mode', 'dispatch_reference']],
            'job_completed' => ['module' => 'Job Cards', 'variables' => ['customer_name', 'job_card_no']],
        ];
    }
}

if (!function_exists('subhiksha_watzup_get_sent_messages')) {
    function subhiksha_watzup_get_sent_messages(mysqli $conn, int $limit = 10, int $page = 1): array
    {
        $apiUrl = subhiksha_wa_setting($conn, 'watzup_sent_api_url', 'https://bulky.watzup.in/api/get/wa.sent');
        $apiToken = subhiksha_wa_setting($conn, 'watzup_api_token', '');

        if ($apiUrl === '' || $apiToken === '') {
            return [
                'success' => false,
                'message' => 'Watzup sent report API URL or secret key missing.',
                'http_code' => 0,
                'response' => '',
                'data' => null
            ];
        }

        $query = http_build_query([
            'secret' => $apiToken,
            'limit' => max(1, $limit),
            'page' => max(1, $page)
        ]);

        $url = $apiUrl . (str_contains($apiUrl, '?') ? '&' : '?') . $query;
        $httpCode = 0;
        $rawResponse = '';
        $effectiveUrl = '';

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
            $rawResponse = (string)curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            if (curl_errno($ch)) {
                $rawResponse = curl_error($ch);
            }
            curl_close($ch);
        } catch (Throwable $e) {
            $rawResponse = $e->getMessage();
        }

        $decoded = json_decode($rawResponse, true);
        $success = $httpCode === 200 && is_array($decoded) && ((int)($decoded['status'] ?? 0) === 200 || strtolower((string)($decoded['status'] ?? '')) === 'success');

        return [
            'success' => $success,
            'message' => $success ? 'Watzup sent messages fetched successfully.' : 'Unable to fetch Watzup sent messages.',
            'http_code' => $httpCode,
            'effective_url' => $effectiveUrl,
            'response' => $rawResponse,
            'data' => $decoded
        ];
    }
}
