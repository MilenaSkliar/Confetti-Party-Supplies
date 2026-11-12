<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(int $status, bool $ok, string $message): void
{
    http_response_code($status);
    echo json_encode(['success' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, false, 'Please submit the enquiry form using POST.');
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) {
    respond(413, false, 'Your request is too large. Please shorten your message.');
}
$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
if ($contentType === 'application/json') {
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if ($raw === false || strlen($raw) > 65536) {
        respond(413, false, 'Your request is too large.');
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
        respond(400, false, 'Please send a valid enquiry.');
    }
} elseif ($contentType === 'application/x-www-form-urlencoded' || $contentType === 'multipart/form-data') {
    $data = $_POST;
} else {
    respond(415, false, 'Unsupported request format.');
}

function field(array $data, string $key): string
{
    return isset($data[$key]) && is_scalar($data[$key]) ? trim((string) $data[$key]) : '';
}
function textLength(string $value): int
{
    $characters = preg_match_all('/./us', $value);
    $surrogatePairs = preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $value);
    return $characters === false || $surrogatePairs === false ? -1 : $characters + $surrogatePairs;
}
$name = field($data, 'name');
$email = field($data, 'email');
$service = field($data, 'service');
$message = field($data, 'message');
$privacy = strtolower(field($data, 'privacy'));
if (field($data, 'website') !== '') {
    respond(422, false, 'The enquiry could not be accepted. Please try again.');
}
if ($name === '' || textLength($name) < 1 || textLength($name) > 120 || preg_match('/[\r\n\x00]/', $name)) {
    respond(422, false, 'Please enter a valid name, up to 120 characters.');
}
if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n\x00]/', $email)) {
    respond(422, false, 'Please enter a valid email address.');
}
if ($service === '' || textLength($service) < 1 || textLength($service) > 160 || preg_match('/[\r\n\x00]/', $service)) {
    respond(422, false, 'Please select an enquiry topic.');
}
if (textLength($message) < 10 || textLength($message) > 6000 || strpos($message, "\0") !== false) {
    respond(422, false, 'Please write a message between 10 and 6,000 characters.');
}
if (!in_array($privacy, ['1', 'true', 'on', 'yes'], true)) {
    respond(422, false, 'Please agree to the Privacy Policy before sending.');
}

$configPath = __DIR__ . '/config/mail.php';
$config = is_file($configPath) ? require $configPath : [];
if (!is_array($config)) {
    respond(503, false, 'The enquiry service is not configured yet. Please try again later.');
}
$recipient = trim((string) ($config['recipient'] ?? ''));
$sitePath = __DIR__ . '/config/config.js';
if ($recipient === '' && is_file($sitePath)) {
    $source = file_get_contents($sitePath);
    if (is_string($source) && preg_match('/\A\s*window\.SITE_CONFIG\s*=\s*(\{.*\})\s*;\s*\z/s', $source, $matches) === 1) {
        $site = json_decode($matches[1], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($site) && isset($site['email']) && is_string($site['email'])) {
            $recipient = trim($site['email']);
        }
    }
}
$sender = trim((string) ($config['sender'] ?? ''));
function realEmail(string $email): bool
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n\x00]/', $email)) {
        return false;
    }
    $domain = strtolower(substr(strrchr($email, '@'), 1));
    return !preg_match('/(^|\.)(example\.(com|net|org)|example|invalid|localhost|test)$/', $domain);
}
if (empty($config['enabled']) || !realEmail($recipient) || !realEmail($sender) || !function_exists('mail')) {
    respond(503, false, 'Online enquiries are not connected yet. Please contact us by email or try again later.');
}

$address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$key = hash('sha256', __DIR__ . '|' . $address);
$limitPath = sys_get_temp_dir() . '/confetti-contact-' . $key . '.json';
$handle = @fopen($limitPath, 'c+');
if ($handle === false || !flock($handle, LOCK_EX)) {
    if (is_resource($handle)) { fclose($handle); }
    respond(503, false, 'The enquiry service is temporarily unavailable. Please try again later.');
}
$previous = stream_get_contents($handle);
$attempts = json_decode($previous !== false ? $previous : '[]', true);
$now = time();
$attempts = is_array($attempts) ? array_values(array_filter($attempts, static function ($stamp) use ($now): bool {
    return is_int($stamp) && $stamp > $now - 3600;
})) : [];
$interval = max(10, (int) ($config['rate_limit_seconds'] ?? 30));
$maximum = max(1, min(30, (int) ($config['max_requests_per_hour'] ?? 8)));
if (count($attempts) >= $maximum || ($attempts !== [] && $now - (int) end($attempts) < $interval)) {
    flock($handle, LOCK_UN);
    fclose($handle);
    header('Retry-After: ' . $interval);
    respond(429, false, 'Please wait a little before sending another enquiry.');
}
$attempts[] = $now;
rewind($handle);
$written = fwrite($handle, (string) json_encode($attempts));
if ($written !== false) {
    ftruncate($handle, ftell($handle));
    fflush($handle);
}
flock($handle, LOCK_UN);
fclose($handle);
if ($written === false) {
    respond(503, false, 'The enquiry service is temporarily unavailable. Please try again later.');
}

$subject = (string) ($config['subject'] ?? 'New celebration enquiry');
$subject = str_replace(["\r", "\n", "\0"], '', $subject);
$body = "Name: {$name}\nEmail: {$email}\nTopic: {$service}\n\nMessage:\n{$message}\n\nPrivacy consent: accepted\nReceived (UTC): " . gmdate('c');
$headers = [
    'From: ' . $sender,
    'Reply-To: ' . $email,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
];
$accepted = @mail($recipient, $subject, $body, implode("\r\n", $headers));
if (!$accepted) {
    respond(502, false, 'Your enquiry could not be sent. Please try again later or contact us by email.');
}
respond(200, true, 'Thank you! We have successfully received your request. Our team will review your information and get back to you shortly.');
