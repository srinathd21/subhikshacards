<?php

/*
|--------------------------------------------------------------------------
| Error Reporting
|--------------------------------------------------------------------------
| Remove these lines after testing.
*/

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/html; charset=UTF-8');


/*
|--------------------------------------------------------------------------
| WhatsApp API Configuration
|--------------------------------------------------------------------------
*/

$accessToken = 'EAGXHGKbsZCLgBSPnoXp0noipSUD5llBf9D9MfaB0uVLvxOZClqItKUNhE0NjGKhvt3gjcih7HmxNRWUJKxuOpltPGQkqeWZCyLzZCVlhg8tZCXqwZAShkJvM1o28guAxO7hpTeS7iiCdDj0ReYUA4ZBzBaAVu7gCg33jU5dMFkFEdbttPiZCaL6ZAPk7sUS9gKAZDZD';

$phoneNumberId = '1254985744359812';

// Send all templates to all these WhatsApp numbers.
// Use country code 91 and do not include the + symbol.
$customerNumbers = array(
    '917200314099',
    '918973458525',
    '919600919775'
);

// All templates use English language code.
$languageCode = 'en';


/*
|--------------------------------------------------------------------------
| Check cURL
|--------------------------------------------------------------------------
*/

if (!function_exists('curl_init')) {
    die('PHP cURL extension is not enabled.');
}


/*
|--------------------------------------------------------------------------
| Clean Template Variable
|--------------------------------------------------------------------------
*/

function cleanWhatsAppText($value)
{
    if ($value === null) {
        return '';
    }

    $text = (string)$value;

    $text = str_replace(
        array("\r", "\n", "\t"),
        ' ',
        $text
    );

    $cleanedText = preg_replace('/\s{2,}/u', ' ', $text);

    if ($cleanedText === null) {
        $cleanedText = $text;
    }

    return trim($cleanedText);
}


/*
|--------------------------------------------------------------------------
| Create Text Parameters
|--------------------------------------------------------------------------
*/

function makeTextParameters($values)
{
    $parameters = array();

    foreach ($values as $value) {
        $parameters[] = array(
            'type' => 'text',
            'text' => cleanWhatsAppText($value)
        );
    }

    return $parameters;
}


/*
|--------------------------------------------------------------------------
| Send One WhatsApp Template
|--------------------------------------------------------------------------
*/

function sendWhatsAppTemplate(
    $accessToken,
    $phoneNumberId,
    $customerNumber,
    $templateName,
    $languageCode,
    $headerValues,
    $bodyValues,
    $buttonComponents
) {
    $url = 'https://graph.facebook.com/v23.0/'
        . rawurlencode($phoneNumberId)
        . '/messages';

    $components = array();


    /*
    |--------------------------------------------------------------------------
    | Header Parameters
    |--------------------------------------------------------------------------
    */

    if (!empty($headerValues)) {
        $components[] = array(
            'type' => 'header',
            'parameters' => makeTextParameters($headerValues)
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Body Parameters
    |--------------------------------------------------------------------------
    */

    if (!empty($bodyValues)) {
        $components[] = array(
            'type' => 'body',
            'parameters' => makeTextParameters($bodyValues)
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Button Parameters
    |--------------------------------------------------------------------------
    */

    if (!empty($buttonComponents)) {
        foreach ($buttonComponents as $buttonComponent) {
            $components[] = $buttonComponent;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Request Payload
    |--------------------------------------------------------------------------
    */

    $payload = array(
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => preg_replace('/[^0-9]/', '', $customerNumber),
        'type' => 'template',
        'template' => array(
            'name' => $templateName,
            'language' => array(
                'code' => $languageCode
            ),
            'components' => $components
        )
    );

    $jsonPayload = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($jsonPayload === false) {
        return array(
            'success' => false,
            'template' => $templateName,
            'http_code' => 0,
            'error' => 'JSON encoding failed.',
            'json_error' => json_last_error_msg()
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Execute API Request
    |--------------------------------------------------------------------------
    */

    $ch = curl_init();

    curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => array(
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            'Accept: application/json'
        ),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ));

    $response = curl_exec($ch);

    $curlErrorNumber = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);


    /*
    |--------------------------------------------------------------------------
    | cURL Error
    |--------------------------------------------------------------------------
    */

    if ($response === false || $curlErrorNumber !== 0) {
        return array(
            'success' => false,
            'template' => $templateName,
            'http_code' => $httpCode,
            'error' => $curlError,
            'curl_error_number' => $curlErrorNumber
        );
    }

    $decodedResponse = json_decode($response, true);

    if (!is_array($decodedResponse)) {
        return array(
            'success' => false,
            'template' => $templateName,
            'http_code' => $httpCode,
            'error' => 'Invalid response received from WhatsApp.',
            'raw_response' => $response
        );
    }

    return array(
        'success' => ($httpCode >= 200 && $httpCode < 300),
        'template' => $templateName,
        'http_code' => $httpCode,
        'response' => $decodedResponse
    );
}


/*
|--------------------------------------------------------------------------
| Validate Configuration
|--------------------------------------------------------------------------
*/

if (
    empty($accessToken) ||
    $accessToken === 'PASTE_NEW_WHATSAPP_ACCESS_TOKEN_HERE'
) {
    die('Please enter your new WhatsApp access token.');
}

if (empty($phoneNumberId)) {
    die('WhatsApp Phone Number ID is missing.');
}

if (empty($customerNumbers) || !is_array($customerNumbers)) {
    die('Customer WhatsApp numbers are missing.');
}

foreach ($customerNumbers as $number) {
    $cleanNumber = preg_replace('/[^0-9]/', '', $number);

    if (strlen($cleanNumber) !== 12 || substr($cleanNumber, 0, 2) !== '91') {
        die(
            'Invalid WhatsApp number: '
            . htmlspecialchars($number, ENT_QUOTES, 'UTF-8')
            . '. Use 91 followed by the 10-digit mobile number.'
        );
    }
}


/*
|--------------------------------------------------------------------------
| Common Sample Details
|--------------------------------------------------------------------------
*/

$reportDate = '22-07-2026';

$customerNameTamil = 'செந்தில்';


/*
|--------------------------------------------------------------------------
| Paint Invoice Details
|--------------------------------------------------------------------------
*/

$invoiceRef = 'INV-005471';
$invoiceDate = '22-07-2026';
$invoiceCustomerName = 'Suresh Kumar';
$invoiceMobile = '9876543210';
$invoiceAddress = '12, Main Road, Dharmapuri';

$itemDetails =
    '1. 007-500M2 - VENIAC / Zinc Yellow Primer - 500ML '
    . 'Qty: 1 Pcs Amount: ₹127.00 | '
    . '2. 007-10I3 - Sri Balaji / PVC Blade - 10INCH '
    . 'Qty: 2 Pcs Amount: ₹170.00 | '
    . '3. 007-8I1 - Sri Balaji / Patty Blade - 8INCH '
    . 'Qty: 2 Pcs Amount: ₹50.00 | '
    . '4. 0053-40K4 - Asian Paints / Waterproof Putty (AS) - 40KG '
    . 'Qty: 6 Pcs Amount: ₹6,102.00';

$invoiceTotalAmount = '23,950';


/*
|--------------------------------------------------------------------------
| All Messages to Send
|--------------------------------------------------------------------------
*/

$allMessages = array();


/*
|--------------------------------------------------------------------------
| 1. Telecaller Daily Report
|--------------------------------------------------------------------------
|
| Body:
| {{1}} Date
| {{2}} Telecaller Name
| {{3}} Total Calls
| {{4}} Contacted
| {{5}} Pending
|
*/

$allMessages[] = array(
    'template_name' => 'telecaller_daily_report',

    'header_values' => array(),

    'body_values' => array(
        $reportDate,
        'Naveen',
        '45',
        '38',
        '7'
    ),

    'button_components' => array()
);


/*
|--------------------------------------------------------------------------
| 2. Paint Invoice
|--------------------------------------------------------------------------
*/

$allMessages[] = array(
    'template_name' => 'paint_invoice',

    'header_values' => array(
        'Sri Ramajayam Paints'
    ),

    'body_values' => array(
        $invoiceRef,
        $invoiceDate,
        $invoiceCustomerName,
        $invoiceMobile,
        $invoiceAddress,
        $itemDetails,
        $invoiceTotalAmount
    ),

    'button_components' => array(
        array(
            'type' => 'button',
            'sub_type' => 'url',
            'index' => '0',
            'parameters' => array(
                array(
                    'type' => 'text',
                    'text' => cleanWhatsAppText($invoiceRef)
                )
            )
        )
    )
);


/*
|--------------------------------------------------------------------------
| 3. Payment Confirmation
|--------------------------------------------------------------------------
|
| Header:
| {{1}} Customer Name
|
| Body:
| {{1}} Customer Name
| {{2}} Received Amount
| {{3}} Payment Date
| {{4}} Collected By
| {{5}} Remaining Due
|
| Do not include ₹ in amount values because the approved template already
| contains the ₹ symbol.
|
*/

$allMessages[] = array(
    'template_name' => 'sriramajayam_payment_confirmation',

    'header_values' => array(
        $customerNameTamil
    ),

    'body_values' => array(
        $customerNameTamil,
        '5,000',
        '22-07-2026',
        'NAVEEN',
        '2,000'
    ),

    'button_components' => array()
);


/*
|--------------------------------------------------------------------------
| 4. Payment Reminder
|--------------------------------------------------------------------------
|
| The template spelling is retained exactly as provided:
| sri_ramajaym_payment_remainder
|
*/

$allMessages[] = array(
    'template_name' => 'sri_ramajaym_payment_remainder',

    'header_values' => array(
        $customerNameTamil
    ),

    'body_values' => array(
        $customerNameTamil,
        '7,000',
        '22-07-2026'
    ),

    'button_components' => array()
);


/*
|--------------------------------------------------------------------------
| 5. Collection Staff Daily Report
|--------------------------------------------------------------------------
|
| Body:
| {{1}} Date
| {{2}} Collection Staff Name
| {{3}} Total Visits
| {{4}} Total Collection
|
*/

$allMessages[] = array(
    'template_name' => 'collection_staff_daily_report',

    'header_values' => array(),

    'body_values' => array(
        $reportDate,
        'Naveen',
        '25',
        '85,000'
    ),

    'button_components' => array()
);



/*
|--------------------------------------------------------------------------
| 6. New Customer Assigned
|--------------------------------------------------------------------------
|
| Template name:
| new_customer_assigned
|
| Header:
| NEW CUSTOMER ASSIGNED
|
| The header is fixed text in the approved template, so no header
| variable must be sent.
|
| Body:
| {{1}} Assigned Staff Name
| {{2}} Assigned Date and Time
| {{3}} Assigned By
|
*/

$allMessages[] = array(
    'template_name' => 'new_customer_assigned',

    // The approved header is static text, so keep this empty.
    'header_values' => array(),

    'body_values' => array(
        'Naveen',
        '22-07-2026 05:45 PM',
        'Admin'
    ),

    'button_components' => array()
);


/*
|--------------------------------------------------------------------------
| Send All Messages
|--------------------------------------------------------------------------
*/

$results = array();

$successCount = 0;
$failedCount = 0;

foreach ($customerNumbers as $customerNumber) {
    foreach ($allMessages as $message) {
        $result = sendWhatsAppTemplate(
            $accessToken,
            $phoneNumberId,
            $customerNumber,
            $message['template_name'],
            $languageCode,
            $message['header_values'],
            $message['body_values'],
            $message['button_components']
        );

        // Store the recipient with every API result.
        $result['recipient'] = $customerNumber;
        $results[] = $result;

        if (!empty($result['success'])) {
            $successCount++;
        } else {
            $failedCount++;
        }

        /*
        |--------------------------------------------------------------------------
        | Small Delay Between Messages
        |--------------------------------------------------------------------------
        |
        | 300000 microseconds = 0.3 seconds.
        |
        */

        usleep(300000);
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>WhatsApp All Templates Result</title>

    <style>
    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        padding: 30px 15px;
        background: #f3f5f9;
        color: #1f2937;
        font-family: Arial, sans-serif;
    }

    .container {
        width: 100%;
        max-width: 1000px;
        margin: 0 auto;
    }

    .summary-card,
    .result-card {
        margin-bottom: 18px;
        padding: 22px;
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.05);
    }

    .summary-card h2 {
        margin-top: 0;
    }

    .recipient-list {
        margin: 10px 0 18px;
        padding-left: 22px;
    }

    .recipient-list li {
        margin: 5px 0;
    }

    .summary-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 12px;
        margin-top: 18px;
    }

    .summary-item {
        padding: 15px;
        background: #f8fafc;
        border-radius: 8px;
    }

    .summary-label {
        display: block;
        margin-bottom: 5px;
        color: #6b7280;
        font-size: 13px;
    }

    .summary-value {
        font-size: 23px;
        font-weight: bold;
    }

    .success {
        color: #15803d;
    }

    .failed {
        color: #b91c1c;
    }

    .result-card h3 {
        margin-top: 0;
        margin-bottom: 10px;
    }

    .status {
        display: inline-block;
        margin-bottom: 12px;
        padding: 6px 11px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: bold;
    }

    .status-success {
        color: #166534;
        background: #dcfce7;
    }

    .status-failed {
        color: #991b1b;
        background: #fee2e2;
    }

    pre {
        margin-bottom: 0;
        padding: 15px;
        overflow: auto;
        background: #111827;
        color: #f9fafb;
        border-radius: 8px;
        white-space: pre-wrap;
        word-break: break-word;
        line-height: 1.5;
    }

    @media (max-width: 650px) {
        .summary-grid {
            grid-template-columns: 1fr;
        }
    }
    </style>
</head>

<body>

    <div class="container">

        <div class="summary-card">

            <h2>WhatsApp Messages Sending Result</h2>

            <p>
                All templates were processed for:
            </p>

            <ul class="recipient-list">
                <?php foreach ($customerNumbers as $number): ?>
                <li>
                    <strong>
                        <?php
                        echo htmlspecialchars(
                            $number,
                            ENT_QUOTES,
                            'UTF-8'
                        );
                        ?>
                    </strong>
                </li>
                <?php endforeach; ?>
            </ul>

            <div class="summary-grid">

                <div class="summary-item">
                    <span class="summary-label">Total Messages</span>

                    <span class="summary-value">
                        <?php echo count($allMessages) * count($customerNumbers); ?>
                    </span>
                </div>

                <div class="summary-item">
                    <span class="summary-label">Successfully Sent</span>

                    <span class="summary-value success">
                        <?php echo $successCount; ?>
                    </span>
                </div>

                <div class="summary-item">
                    <span class="summary-label">Failed</span>

                    <span class="summary-value failed">
                        <?php echo $failedCount; ?>
                    </span>
                </div>

            </div>

        </div>

        <?php foreach ($results as $index => $result): ?>

        <?php
        $isSuccess = !empty($result['success']);

        $templateName = isset($result['template'])
            ? $result['template']
            : 'Unknown template';

        $httpCode = isset($result['http_code'])
            ? (int)$result['http_code']
            : 0;

        $recipient = isset($result['recipient'])
            ? $result['recipient']
            : 'Unknown recipient';
        ?>

        <div class="result-card">

            <h3>
                <?php echo ($index + 1); ?>.
                <?php
                echo htmlspecialchars(
                    $templateName,
                    ENT_QUOTES,
                    'UTF-8'
                );
                ?>
            </h3>

            <span class="status <?php
                echo $isSuccess
                    ? 'status-success'
                    : 'status-failed';
            ?>">
                <?php
                echo $isSuccess
                    ? 'Sent Successfully'
                    : 'Sending Failed';
                ?>
            </span>

            <p>
                <strong>Recipient:</strong>
                <?php
                echo htmlspecialchars(
                    $recipient,
                    ENT_QUOTES,
                    'UTF-8'
                );
                ?>
            </p>

            <p>
                <strong>HTTP Status:</strong>
                <?php echo $httpCode; ?>
            </p>

            <pre><?php
                echo htmlspecialchars(
                    json_encode(
                        $result,
                        JSON_PRETTY_PRINT
                        | JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES
                    ),
                    ENT_QUOTES,
                    'UTF-8'
                );
            ?></pre>

        </div>

        <?php endforeach; ?>

    </div>

</body>

</html>