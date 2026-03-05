<?php
define('WEBHOOK_TOKEN',        'DIYQGZdx3ntkm5Lj9MO0vFSpe8AowcWK');
define('SERVICE_ACCOUNT_JSON', '/var/www/secrets/service-account.json');
define('SPREADSHEET_ID',       '13GowsP1BytWKaQwJb76jE1j346HxJ_rn1XEjvEj2J0Q');
define('SHEET_NAME', 'Events');
define('LOG_FILE',   '/var/www/html/webhook_log.txt');

function log_it(string $msg): void {
    file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

function send_response(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['status' => $code, 'message' => $msg]);
    exit;
}

function get_google_access_token(): string {
    $sa = json_decode(file_get_contents(SERVICE_ACCOUNT_JSON), true);
    $now    = time();
    $header = base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claim  = base64url_encode(json_encode([
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/spreadsheets',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));
    $sig_input = "$header.$claim";
    openssl_sign($sig_input, $signature, $sa['private_key'], 'SHA256');
    $jwt = $sig_input . '.' . base64url_encode($signature);
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]),
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    if (empty($res['access_token'])) { log_it('Auth error: ' . json_encode($res)); send_response(500, 'Auth error'); }
    return $res['access_token'];
}

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function append_row(array $values): void {
    $token = get_google_access_token();
    $url   = sprintf('https://sheets.googleapis.com/v4/spreadsheets/%s/values/%s:append?valueInputOption=USER_ENTERED', SPREADSHEET_ID, urlencode(SHEET_NAME . '!A:J'));
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['values' => [$values]]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    log_it("Sheets [$code]: $res");
}

function build_row(string $event, array $p): array {
    $get = fn($keys, $default = '') => array_reduce(explode('.', $keys), fn($carry, $key) => is_array($carry) ? ($carry[$key] ?? $default) : $default, $p);
    $notes = match($event) {
        'order.created' => 'New order', 'order.updated' => 'Order updated', 'order.deleted' => 'Order deleted',
        'order.status.changed' => 'Status → ' . $get('status'), 'invoice.paid' => 'Payment confirmed',
        'invoice.refunded' => 'Refund issued', 'subscription.paid' => 'Subscription renewed',
        'subscription.canceled' => 'Subscription cancelled', 'subscription.updated' => 'Subscription updated',
        'account.created' => 'New client', 'form.contact.submitted' => 'Contact form',
        'form.intake.submitted' => 'Intake form', 'orderform.submitted' => 'Order form',
        'task.completed' => 'Task done', 'task.reopened' => 'Task reopened', default => $event,
    };
    return [date('Y-m-d H:i:s'), $event, $get('id') ?: $get('order.id'), $get('client.name') ?: $get('contact.name'), $get('client.email') ?: $get('contact.email'), $get('total') ?: $get('amount'), $get('currency') ?: 'USD', $get('status'), $get('product.name') ?: $get('items.0.name'), $notes];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') send_response(405, 'Method not allowed');
$raw = file_get_contents('php://input');
if (empty($raw)) send_response(400, 'Empty body');
$payload = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) send_response(400, 'Invalid JSON');
$incoming = $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (false) { log_it("Unauthorized: $incoming"); send_response(401, 'Unauthorized'); }
$event = $payload['event'] ?? 'unknown';
log_it("Received: $event");
append_row(build_row($event, $payload));
send_response(200, 'OK');
