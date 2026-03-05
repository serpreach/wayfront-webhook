<?php
function get_gmail_token(): string {
    $token_file = '/var/www/secrets/token_holly.json';
    $token_data = json_decode(file_get_contents($token_file), true);
    $expiry = strtotime($token_data['expiry'] ?? '2000-01-01');
    if (time() < $expiry - 60) return $token_data['token'];
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['client_id' => $token_data['client_id'], 'client_secret' => $token_data['client_secret'], 'refresh_token' => $token_data['refresh_token'], 'grant_type' => 'refresh_token']), CURLOPT_RETURNTRANSFER => true]);
    $res = json_decode(curl_exec($ch), true); curl_close($ch);
    if (empty($res['access_token'])) { log_it('Token refresh failed: ' . json_encode($res)); return ''; }
    $token_data['token'] = $res['access_token'];
    $token_data['expiry'] = date('Y-m-d\TH:i:s\Z', time() + 3600);
    file_put_contents($token_file, json_encode($token_data));
    return $res['access_token'];
}

function send_order_email(array $d, string $event): void {
    $access_token = get_gmail_token();
    if (empty($access_token)) { log_it('No access token'); return; }

    $client_name  = $d['client']['name'] ?? 'Unknown';
    $client_email = $d['client']['email'] ?? '';
    $service      = $d['service'] ?? '';
    $order_id     = $d['id'] ?? '';
    $price        = $d['price'] ?? '0.00';
    $currency     = $d['currency'] ?? 'USD';
    $paysys       = $d['paysys'] ?? '';
    $quantity     = $d['quantity'] ?? 1;
    $invoice_url  = $d['invoice']['view_link'] ?? '';
    $form_data    = $d['form_data'] ?? [];

    $form_html = '';
    foreach ($form_data as $field => $value) {
        $form_html .= "<tr><td style='padding:8px 6px;color:#666;border-bottom:1px solid #eee;'><b>" . htmlspecialchars($field) . "</b></td><td style='padding:8px 6px;border-bottom:1px solid #eee;'>" . htmlspecialchars($value) . "</td></tr>";
    }

    $body = "<html><body style='font-family:Arial,sans-serif;max-width:620px;margin:0 auto;'>
<h2 style='color:#e53e3e;border-bottom:2px solid #e53e3e;padding-bottom:8px;'>New Order Received</h2>
<table style='width:100%;border-collapse:collapse;margin-bottom:24px;background:#f9f9f9;border-radius:6px;'>
<tr><td style='padding:8px 12px;color:#666;width:40%;'><b>Order ID</b></td><td style='padding:8px 12px;'>{$order_id}</td></tr>
<tr style='background:#fff;'><td style='padding:8px 12px;color:#666;'><b>Client Name</b></td><td style='padding:8px 12px;'>{$client_name}</td></tr>
<tr><td style='padding:8px 12px;color:#666;'><b>Email</b></td><td style='padding:8px 12px;'><a href='mailto:{$client_email}'>{$client_email}</a></td></tr>
<tr style='background:#fff;'><td style='padding:8px 12px;color:#666;'><b>Service</b></td><td style='padding:8px 12px;'>{$service}</td></tr>
<tr><td style='padding:8px 12px;color:#666;'><b>Price</b></td><td style='padding:8px 12px;'>{$currency} {$price}</td></tr>
<tr style='background:#fff;'><td style='padding:8px 12px;color:#666;'><b>Payment Method</b></td><td style='padding:8px 12px;'>{$paysys}</td></tr>
<tr><td style='padding:8px 12px;color:#666;'><b>Quantity</b></td><td style='padding:8px 12px;'>{$quantity}</td></tr>
</table>"
. ($form_html ? "<h3 style='color:#333;margin-bottom:8px;'>Form Details</h3><table style='width:100%;border-collapse:collapse;background:#f9f9f9;border-radius:6px;'>{$form_html}</table>" : '')
. ($invoice_url ? "<p style='margin-top:24px;'><a href='{$invoice_url}' style='background:#e53e3e;color:#fff;padding:10px 24px;text-decoration:none;border-radius:4px;font-weight:bold;'>View Invoice</a></p>" : '')
. "</body></html>";

    $subject   = "New Order - {$client_name} - {$service}";
    $raw_email = "From: SERPreach Orders <holly@outreach.solutions>\r\nTo: collab@outreach.solutions\r\nSubject: {$subject}\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n{$body}";
    $email = ['raw' => rtrim(strtr(base64_encode($raw_email), '+/', '-_'), '=')];

    $ch = curl_init('https://gmail.googleapis.com/gmail/v1/users/me/messages/send');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($email), CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $access_token]]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    log_it("Email sent [{$code}]: {$res}");
}
