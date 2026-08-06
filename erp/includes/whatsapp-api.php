<?php
/**
 * includes/whatsapp-api.php
 * Meta WhatsApp Cloud API integration for Subhiksha Cards ERP.
 *
 * Existing project usage remains unchanged:
 *
 * $result = subhiksha_send_template_whatsapp(
 *     $conn,
 *     'enquiry_completed',
 *     $customerMobile,
 *     [
 *         'customer_name' => $customerName,
 *         'enquiry_no' => $enquiryNo,
 *         'function_type' => $functionType,
 *         'function_date' => $functionDate
 *     ],
 *     [
 *         'related_module' => 'Enquiries',
 *         'related_id' => $enquiryId,
 *         'customer_id' => $customerId
 *     ]
 * );
 */

if (!function_exists('subhiksha_wa_table_exists')) {
    function subhiksha_wa_table_exists(mysqli $conn, string $table): bool
    {
        try {
            $table = $conn->real_escape_string($table);
            $result = $conn->query("SHOW TABLES LIKE '{$table}'");
            $exists = $result && $result->num_rows > 0;
            if ($result) {
                $result->free();
            }
            return $exists;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('subhiksha_wa_setting')) {
    function subhiksha_wa_setting(
        mysqli $conn,
        string $key,
        string $default = ''
    ): string {
        try {
            if (!subhiksha_wa_table_exists($conn, 'system_settings')) {
                return $default;
            }

            $stmt = $conn->prepare(
                'SELECT setting_value
                 FROM system_settings
                 WHERE setting_key = ?
                 LIMIT 1'
            );
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

if (!function_exists('subhiksha_wa_config_value')) {
    /**
     * Environment variables take priority over database settings.
     */
    function subhiksha_wa_config_value(
        mysqli $conn,
        array $environmentKeys,
        array $settingKeys,
        string $default = ''
    ): string {
        foreach ($environmentKeys as $environmentKey) {
            $environmentValue = getenv((string)$environmentKey);
            if (
                $environmentValue !== false
                && trim((string)$environmentValue) !== ''
            ) {
                return trim((string)$environmentValue);
            }
        }

        foreach ($settingKeys as $settingKey) {
            $value = trim(
                subhiksha_wa_setting($conn, (string)$settingKey, '')
            );
            if ($value !== '') {
                return $value;
            }
        }

        return $default;
    }
}

if (!function_exists('subhiksha_wa_normalize_mobile')) {
    function subhiksha_wa_normalize_mobile(
        string $mobile,
        string $countryCode = '91'
    ): string {
        $mobile = (string)preg_replace('/\D+/', '', $mobile);

        if ($mobile === '') {
            return '';
        }

        if (strlen($mobile) === 10) {
            return $countryCode . $mobile;
        }

        if (strlen($mobile) === 11 && substr($mobile, 0, 1) === '0') {
            return $countryCode . substr($mobile, 1);
        }

        return $mobile;
    }
}

if (!function_exists('subhiksha_wa_clean_text')) {
    function subhiksha_wa_clean_text($value): string
    {
        if ($value === null) {
            return '';
        }

        $text = str_replace(
            ["\r", "\n", "\t"],
            ' ',
            (string)$value
        );
        $cleaned = preg_replace('/\s{2,}/u', ' ', $text);

        return trim($cleaned === null ? $text : $cleaned);
    }
}

if (!function_exists('subhiksha_wa_render_template')) {
    function subhiksha_wa_render_template(
        string $message,
        array $variables = []
    ): string {
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
    function subhiksha_wa_get_template(
        mysqli $conn,
        string $templateKey
    ): ?array {
        try {
            if (!subhiksha_wa_table_exists($conn, 'whatsapp_templates')) {
                return null;
            }

            $stmt = $conn->prepare(
                'SELECT id, template_key, template_name, message_body
                 FROM whatsapp_templates
                 WHERE template_key = ?
                   AND is_active = 1
                 LIMIT 1'
            );
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

            $templateId = !empty($data['template_id'])
                ? (int)$data['template_id']
                : null;
            $relatedModule = (string)($data['related_module'] ?? '');
            $relatedId = !empty($data['related_id'])
                ? (int)$data['related_id']
                : null;
            $customerId = !empty($data['customer_id'])
                ? (int)$data['customer_id']
                : null;
            $jobCardId = !empty($data['job_card_id'])
                ? (int)$data['job_card_id']
                : null;
            $mobile = (string)($data['mobile'] ?? '');
            $messageBody = (string)($data['message_body'] ?? '');
            $status = (string)($data['status'] ?? 'pending');
            $providerResponse = (string)($data['provider_response'] ?? '');
            $sentBy = !empty($data['sent_by'])
                ? (int)$data['sent_by']
                : null;
            $sentAt = !empty($data['sent_at'])
                ? (string)$data['sent_at']
                : null;

            $stmt = $conn->prepare(
                'INSERT INTO whatsapp_logs
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
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
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

if (!function_exists('subhiksha_wa_supported_template_keys')) {
    /**
     * The 13 Meta-approved templates and their exact BODY variable order.
     * Do not reorder these values unless the corresponding approved Meta
     * template is changed as well.
     */
    function subhiksha_wa_supported_template_keys(): array
    {
        return [
            'enquiry_completed' => [
                'module' => 'Enquiries',
                'variables' => [
                    'customer_name',
                    'enquiry_no',
                    'function_type',
                    'function_date'
                ]
            ],
            'quotation_created' => [
                'module' => 'Quotations',
                'variables' => [
                    'customer_name',
                    'quotation_no',
                    'function_type',
                    'number_of_items',
                    'item_details',
                    'price',
                    'final_price'
                ]
            ],
            'proforma_created' => [
                'module' => 'Proforma Bills',
                'variables' => [
                    'customer_name',
                    'proforma_no',
                    'product_name',
                    'final_amount',
                    'advance_amount',
                    'balance_amount',
                    'delivery_date',
                    'proforma_pdf_link'
                ]
            ],
            'payment_recieved' => [
                'module' => 'Payments',
                'variables' => [
                    'customer_name',
                    'proforma_no',
                    'payment_no',
                    'paid_amount',
                    'payment_mode',
                    'balance_amount'
                ]
            ],
            'payment_completed_' => [
                'module' => 'Payments',
                'variables' => [
                    'customer_name',
                    'proforma_no',
                    'payment_no',
                    'paid_amount',
                    'total_paid',
                    'balance_amount',
                    'payment_date'
                ]
            ],
            'job_card_created' => [
                'module' => 'Job Cards',
                'variables' => [
                    'customer_name',
                    'job_card_no',
                    'product_name',
                    'order_type',
                    'current_stage',
                    'delivery_date'
                ]
            ],
            'job_card_status' => [
                'module' => 'Job Tracking',
                'variables' => [
                    'customer_name',
                    'job_card_no',
                    'stage_name',
                    'status_name',
                    'product_name',
                    'delivery_date'
                ]
            ],
            'job_stage_completed' => [
                'module' => 'Job Tracking',
                'variables' => [
                    'customer_name',
                    'job_card_no',
                    'stage_name',
                    'delivery_date'
                ]
            ],
            'job_stage_delayed' => [
                'module' => 'Job Tracking',
                'variables' => [
                    'customer_name',
                    'job_card_no',
                    'stage_name',
                    'status_name',
                    'delay_reason',
                    'delay_days',
                    'remarks'
                ]
            ],
            'job_stage_cancelled_' => [
                'module' => 'Job Tracking',
                'variables' => [
                    'customer_name',
                    'job_card_no',
                    'stage_name',
                    'status_name',
                    'remarks'
                ]
            ],
            'job_stage_updated' => [
                'module' => 'Job Tracking',
                'variables' => [
                    'customer_name',
                    'job_card_no',
                    'stage_name',
                    'status_name',
                    'remarks'
                ]
            ],
            'google_review_link' => [
                'module' => 'Reviews',
                'variables' => [
                    'customer_name',
                    'job_card_no'
                ]
            ],
            'design_ready_for_approval' => [
                'module' => 'Customer Approvals',
                'variables' => [
                    'customer_name',
                    'job_card_no',
                    'stage_name',
                    'delivery_date',
                    'approval_link',
                    'tracking_link'
                ]
            ]
        ];
    }
}

if (!function_exists('subhiksha_wa_canonical_template_key')) {
    /**
     * Compatibility for an older ERP status call. The approved Meta template
     * is job_card_status; older job-card pages called the same message
     * job_stage_started.
     *
     * The approved completed-payment Meta template has a trailing underscore,
     * so older calls using payment_completed are routed to payment_completed_.
     * advance_payment_received is deliberately NOT aliased to payment_recieved;
     * the payment page now calls the approved payment_recieved template directly.
     */
    function subhiksha_wa_canonical_template_key(string $templateKey): string
    {
        $templateKey = strtolower(trim($templateKey));

        $aliases = [
            'job_stage_started' => 'job_card_status',
            'job_stage_cancelled' => 'job_stage_cancelled_',
            'job_completed_review_request' => 'google_review_link',
            'proofing_ready_for_approval' => 'design_ready_for_approval',
            'payment_completed' => 'payment_completed_'
        ];

        return $aliases[$templateKey] ?? $templateKey;
    }
}

if (!function_exists('subhiksha_meta_variable_value')) {
    function subhiksha_meta_variable_value(
        string $variableKey,
        array $variables,
        bool &$found
    ): string {
        if (array_key_exists($variableKey, $variables)) {
            $found = true;
            return subhiksha_wa_clean_text($variables[$variableKey]);
        }

        $aliases = [
            'function_type' => ['product_type', 'requirement'],
            'function_date' => ['event_date', 'enquiry_function_date'],
            'next_followup_date' => ['next_callback_at', 'next_callback_date'],
            'number_of_items' => ['item_count', 'total_items', 'total_qty'],
            'price' => ['sub_total', 'subtotal'],
            'final_price' => ['final_amount', 'grand_total'],
            'proforma_pdf_link' => [
                'proforma_download_link',
                'proforma_view_link',
                'customer_proforma_link',
                'invoice_link'
            ],
            'stage_name' => ['current_stage', 'completed_stage'],
            'status_name' => ['status'],
            'delay_reason' => ['delay_reason_name'],
            'dispatch_reference' => ['reference_no', 'dispatch_no']
        ];

        foreach (($aliases[$variableKey] ?? []) as $alias) {
            if (array_key_exists($alias, $variables)) {
                $found = true;
                return subhiksha_wa_clean_text($variables[$alias]);
            }
        }

        $found = false;
        return '';
    }
}

if (!function_exists('subhiksha_meta_template_parameters')) {
    function subhiksha_meta_template_parameters(
        string $templateKey,
        array $variables
    ): array {
        $templateKey = subhiksha_wa_canonical_template_key($templateKey);
        $definitions = subhiksha_wa_supported_template_keys();

        if (!isset($definitions[$templateKey])) {
            return [
                'success' => false,
                'parameters' => [],
                'missing_variables' => [],
                'message' => 'Unsupported Meta template key: ' . $templateKey
            ];
        }

        $parameters = [];
        $missingVariables = [];

        foreach ($definitions[$templateKey]['variables'] as $variableKey) {
            $found = false;
            $value = subhiksha_meta_variable_value(
                $variableKey,
                $variables,
                $found
            );

            if (!$found) {
                $missingVariables[] = $variableKey;
            }

            $parameters[] = [
                'type' => 'text',
                'text' => $value
            ];
        }

        return [
            'success' => empty($missingVariables),
            'parameters' => $parameters,
            'missing_variables' => $missingVariables,
            'message' => empty($missingVariables)
                ? 'Template parameters prepared.'
                : 'Missing template variables: '
                    . implode(', ', $missingVariables)
        ];
    }
}

if (!function_exists('subhiksha_meta_config')) {
    function subhiksha_meta_config(mysqli $conn): array
    {
        $version = subhiksha_wa_config_value(
            $conn,
            ['SUBHIKSHA_WHATSAPP_GRAPH_VERSION'],
            ['meta_whatsapp_graph_version'],
            'v23.0'
        );
        $version = trim($version);
        if ($version !== '' && substr($version, 0, 1) !== 'v') {
            $version = 'v' . $version;
        }

        return [
            'access_token' => subhiksha_wa_config_value(
                $conn,
                [
                    'SUBHIKSHA_WHATSAPP_ACCESS_TOKEN',
                    'SUBHIKSHA_WHATSAPP_API_KEY'
                ],
                ['meta_whatsapp_access_token'],
                ''
            ),
            'business_account_id' => subhiksha_wa_config_value(
                $conn,
                ['SUBHIKSHA_WHATSAPP_BUSINESS_ACCOUNT_ID'],
                ['meta_whatsapp_business_account_id'],
                ''
            ),
            'phone_number_id' => subhiksha_wa_config_value(
                $conn,
                ['SUBHIKSHA_WHATSAPP_PHONE_NUMBER_ID'],
                ['meta_whatsapp_phone_number_id'],
                ''
            ),
            'display_phone_number' => subhiksha_wa_setting(
                $conn,
                'meta_whatsapp_display_phone_number',
                ''
            ),
            'graph_version' => $version !== '' ? $version : 'v23.0',
            'language_code' => subhiksha_wa_setting(
                $conn,
                'meta_whatsapp_language_code',
                'en'
            )
        ];
    }
}

if (!function_exists('subhiksha_meta_api_request')) {
    function subhiksha_meta_api_request(
        mysqli $conn,
        string $method,
        string $path,
        ?array $payload = null
    ): array {
        $config = subhiksha_meta_config($conn);

        if ($config['access_token'] === '') {
            return [
                'success' => false,
                'http_code' => 0,
                'message' => 'Meta WhatsApp access token is missing.',
                'response' => null,
                'raw_response' => ''
            ];
        }

        if (!function_exists('curl_init')) {
            return [
                'success' => false,
                'http_code' => 0,
                'message' => 'PHP cURL extension is not enabled.',
                'response' => null,
                'raw_response' => ''
            ];
        }

        $url = 'https://graph.facebook.com/'
            . rawurlencode($config['graph_version'])
            . '/'
            . ltrim($path, '/');

        $method = strtoupper($method);
        $jsonPayload = null;

        if ($payload !== null) {
            $jsonPayload = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            if ($jsonPayload === false) {
                return [
                    'success' => false,
                    'http_code' => 0,
                    'message' => 'WhatsApp JSON encoding failed: '
                        . json_last_error_msg(),
                    'response' => null,
                    'raw_response' => ''
                ];
            }
        }

        $httpCode = 0;
        $rawResponse = '';
        $curlError = '';

        try {
            $ch = curl_init();

            $headers = [
                'Authorization: Bearer ' . $config['access_token'],
                'Accept: application/json'
            ];

            if ($payload !== null) {
                $headers[] = 'Content-Type: application/json';
            }

            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2
            ]);

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
            } elseif ($method !== 'GET') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                if ($jsonPayload !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
                }
            }

            $rawResponse = (string)curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if (curl_errno($ch)) {
                $curlError = curl_error($ch);
            }

            curl_close($ch);
        } catch (Throwable $e) {
            $curlError = $e->getMessage();
        }

        $rawResponse = str_replace(
            $config['access_token'],
            '[REDACTED]',
            $rawResponse
        );
        $decoded = json_decode($rawResponse, true);
        $success = $curlError === ''
            && $httpCode >= 200
            && $httpCode < 300
            && is_array($decoded)
            && empty($decoded['error']);

        $message = $success
            ? 'Meta WhatsApp API request completed.'
            : (
                $curlError !== ''
                    ? $curlError
                    : (string)(
                        $decoded['error']['message']
                        ?? 'Meta WhatsApp API request failed.'
                    )
            );

        return [
            'success' => $success,
            'http_code' => $httpCode,
            'message' => $message,
            'response' => is_array($decoded) ? $decoded : null,
            'raw_response' => $rawResponse
        ];
    }
}

if (!function_exists('subhiksha_meta_get_phone_numbers')) {
    /**
     * Retrieve Phone Number IDs connected to the configured WABA.
     */
    function subhiksha_meta_get_phone_numbers(mysqli $conn): array
    {
        $config = subhiksha_meta_config($conn);

        if ($config['business_account_id'] === '') {
            return [
                'success' => false,
                'http_code' => 0,
                'message' => 'WhatsApp Business Account ID is missing.',
                'data' => []
            ];
        }

        $path = rawurlencode($config['business_account_id'])
            . '/phone_numbers'
            . '?fields=id,display_phone_number,verified_name,quality_rating';
        $result = subhiksha_meta_api_request($conn, 'GET', $path);

        return [
            'success' => !empty($result['success']),
            'http_code' => (int)($result['http_code'] ?? 0),
            'message' => (string)($result['message'] ?? ''),
            'data' => is_array($result['response']['data'] ?? null)
                ? $result['response']['data']
                : [],
            'response' => $result['response'] ?? null
        ];
    }
}

if (!function_exists('subhiksha_wa_failed_result')) {
    function subhiksha_wa_failed_result(
        mysqli $conn,
        array $context,
        string $publicMessage,
        string $providerResponse
    ): array {
        $logId = subhiksha_wa_log($conn, [
            'template_id' => $context['template_id'] ?? null,
            'related_module' => $context['related_module'] ?? '',
            'related_id' => $context['related_id'] ?? null,
            'customer_id' => $context['customer_id'] ?? null,
            'job_card_id' => $context['job_card_id'] ?? null,
            'mobile' => $context['mobile'] ?? '',
            'message_body' => $context['message_body'] ?? '',
            'status' => 'failed',
            'provider_response' => $providerResponse,
            'sent_by' => $context['sent_by'] ?? null,
            'sent_at' => null
        ]);

        return [
            'success' => false,
            'message' => $publicMessage,
            'template_key' => $context['template_key'] ?? '',
            'log_id' => $logId,
            'http_code' => (int)($context['http_code'] ?? 0),
            'mobile' => $context['mobile'] ?? '',
            'response' => $providerResponse
        ];
    }
}

if (!function_exists('subhiksha_send_whatsapp')) {
    function subhiksha_send_whatsapp(
        mysqli $conn,
        array $params
    ): array {
        $mobile = subhiksha_wa_normalize_mobile(
            (string)($params['mobile'] ?? '')
        );
        $requestedTemplateKey = trim((string)($params['template_key'] ?? ''));
        $templateKey = subhiksha_wa_canonical_template_key(
            $requestedTemplateKey
        );
        $variables = (array)($params['variables'] ?? []);
        $message = trim((string)($params['message'] ?? ''));
        $templateId = null;
        $sentBy = !empty($params['sent_by'])
            ? (int)$params['sent_by']
            : (int)($_SESSION['user_id'] ?? 0);

        $context = [
            'template_id' => null,
            'template_key' => $templateKey,
            'related_module' => $params['related_module'] ?? '',
            'related_id' => $params['related_id'] ?? null,
            'customer_id' => $params['customer_id'] ?? null,
            'job_card_id' => $params['job_card_id'] ?? null,
            'mobile' => $mobile,
            'message_body' => '',
            'sent_by' => $sentBy
        ];

        if ($mobile === '') {
            return subhiksha_wa_failed_result(
                $conn,
                $context,
                'Mobile number is required.',
                'Recipient mobile number is empty.'
            );
        }

        if ($templateKey !== '') {
            $template = subhiksha_wa_get_template($conn, $templateKey);

            if (!$template) {
                return subhiksha_wa_failed_result(
                    $conn,
                    $context,
                    'WhatsApp template missing or inactive.',
                    'Active database template not found: ' . $templateKey
                );
            }

            $templateId = (int)$template['id'];
            $message = subhiksha_wa_render_template(
                (string)$template['message_body'],
                $variables
            );
            $context['template_id'] = $templateId;
            $context['message_body'] = $message;
        } else {
            $context['message_body'] = $message;
        }

        if ($message === '') {
            return subhiksha_wa_failed_result(
                $conn,
                $context,
                'Message or template key is required.',
                'No WhatsApp message body was supplied.'
            );
        }

        if (subhiksha_wa_setting($conn, 'whatsapp_enabled', '0') !== '1') {
            return subhiksha_wa_failed_result(
                $conn,
                $context,
                'WhatsApp integration is disabled.',
                'Set whatsapp_enabled = 1 in system_settings.'
            );
        }

        $config = subhiksha_meta_config($conn);

        if (
            $config['access_token'] === ''
            || $config['phone_number_id'] === ''
        ) {
            return subhiksha_wa_failed_result(
                $conn,
                $context,
                'Meta WhatsApp API settings are incomplete.',
                'Access Token or Phone Number ID is missing.'
            );
        }

        if ($templateKey !== '') {
            $parameterResult = subhiksha_meta_template_parameters(
                $templateKey,
                $variables
            );

            if (empty($parameterResult['success'])) {
                return subhiksha_wa_failed_result(
                    $conn,
                    $context,
                    'WhatsApp template variables are incomplete.',
                    (string)$parameterResult['message']
                );
            }

            $components = [];
            if (!empty($parameterResult['parameters'])) {
                $components[] = [
                    'type' => 'body',
                    'parameters' => $parameterResult['parameters']
                ];
            }

            /*
             * payment_completed_ has seven BODY variables. The Proforma PDF
             * link belongs to the approved "Download Invoice" URL button and
             * must be sent as a button component, not as an eighth BODY value.
             */
            if ($templateKey === 'payment_completed_') {
                $invoiceLinkFound = false;
                $invoiceLink = subhiksha_meta_variable_value(
                    'proforma_pdf_link',
                    $variables,
                    $invoiceLinkFound
                );

                if (!$invoiceLinkFound || trim($invoiceLink) === '') {
                    return subhiksha_wa_failed_result(
                        $conn,
                        $context,
                        'WhatsApp template variables are incomplete.',
                        'Missing template variable: proforma_pdf_link'
                    );
                }

                /*
                 * Meta dynamic URL buttons already contain the fixed invoice
                 * URL in the approved template. Only the dynamic Proforma ID
                 * must be supplied here. Passing the complete PDF URL makes
                 * Meta append one URL to another and produces an invalid link.
                 */
                $invoiceButtonValue = '';
                $invoiceUrlParts = parse_url($invoiceLink);
                if (is_array($invoiceUrlParts) && !empty($invoiceUrlParts['query'])) {
                    $invoiceQuery = [];
                    parse_str((string)$invoiceUrlParts['query'], $invoiceQuery);
                    $invoiceButtonValue = trim((string)($invoiceQuery['id'] ?? ''));
                }

                if ($invoiceButtonValue === '') {
                    return subhiksha_wa_failed_result(
                        $conn,
                        $context,
                        'WhatsApp invoice button could not be prepared.',
                        'Proforma ID is missing from proforma_pdf_link.'
                    );
                }

                $components[] = [
                    'type' => 'button',
                    'sub_type' => 'url',
                    'index' => '0',
                    'parameters' => [
                        [
                            'type' => 'text',
                            'text' => $invoiceButtonValue
                        ]
                    ]
                ];
            }

            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $mobile,
                'type' => 'template',
                'template' => [
                    'name' => (string)(
                        $params['meta_template_name']
                        ?? $templateKey
                    ),
                    'language' => [
                        'code' => (string)(
                            $params['language_code']
                            ?? $config['language_code']
                        )
                    ],
                    'components' => $components
                ]
            ];
        } else {
            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $mobile,
                'type' => 'text',
                'text' => [
                    'preview_url' => false,
                    'body' => $message
                ]
            ];
        }

        $apiResult = subhiksha_meta_api_request(
            $conn,
            'POST',
            rawurlencode($config['phone_number_id']) . '/messages',
            $payload
        );
        $messageId = (string)(
            $apiResult['response']['messages'][0]['id']
            ?? ''
        );
        $success = !empty($apiResult['success']) && $messageId !== '';
        $providerResponse = json_encode(
            [
                'provider' => 'meta_cloud',
                'http_code' => (int)($apiResult['http_code'] ?? 0),
                'message_id' => $messageId,
                'response' => $apiResult['response'] ?? null,
                'error' => $success
                    ? null
                    : (string)($apiResult['message'] ?? '')
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $providerResponse = $providerResponse === false
            ? (string)($apiResult['raw_response'] ?? '')
            : $providerResponse;

        $logId = subhiksha_wa_log($conn, [
            'template_id' => $templateId,
            'related_module' => $context['related_module'],
            'related_id' => $context['related_id'],
            'customer_id' => $context['customer_id'],
            'job_card_id' => $context['job_card_id'],
            'mobile' => $mobile,
            'message_body' => $message,
            'status' => $success ? 'sent' : 'failed',
            'provider_response' => $providerResponse,
            'sent_by' => $sentBy,
            'sent_at' => $success ? date('Y-m-d H:i:s') : null
        ]);

        return [
            'success' => $success,
            'message' => $success
                ? 'WhatsApp template sent successfully.'
                : 'WhatsApp sending failed: '
                    . (string)($apiResult['message'] ?? 'Unknown Meta error.'),
            'provider' => 'meta_cloud',
            'template_key' => $templateKey,
            'requested_template_key' => $requestedTemplateKey,
            'message_id' => $messageId,
            'log_id' => $logId,
            'http_code' => (int)($apiResult['http_code'] ?? 0),
            'mobile' => $mobile,
            'body' => $message,
            'response' => $apiResult['response'] ?? null
        ];
    }
}

if (!function_exists('subhiksha_send_template_whatsapp')) {
    function subhiksha_send_template_whatsapp(
        mysqli $conn,
        string $templateKey,
        string $mobile,
        array $variables = [],
        array $meta = []
    ): array {
        $params = $meta;
        unset($params['message']);

        $params['mobile'] = $mobile;
        $params['template_key'] = $templateKey;
        $params['variables'] = $variables;

        return subhiksha_send_whatsapp($conn, $params);
    }
}

if (!function_exists('subhiksha_watzup_get_sent_messages')) {
    /**
     * Compatibility stub for old pages that called the former Watzup report edited.
     * Meta delivery updates should be handled through webhooks and local logs.
     */
    function subhiksha_watzup_get_sent_messages(
        mysqli $conn,
        int $limit = 10,
        int $page = 1
    ): array {
        return [
            'success' => false,
            'message' => 'The Watzup sent report is unavailable because the project now uses Meta WhatsApp Cloud API. Use whatsapp_logs and Meta webhooks.',
            'http_code' => 0,
            'response' => '',
            'data' => null
        ];
    }
}
