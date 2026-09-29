<?php
/**
 * Отправка сообщений в мессенджер MAX (platform-api2.max.ru) с сайта —
 * используется при проверке ежедневного отчёта (daily_report_review.php).
 *
 * Формат запроса (заголовок Authorization без схемы "Bearer", поле "link"
 * для ответа на конкретное сообщение) проверен вживую 29.09.2026 с бота
 * (/home/claude/max) — тот же HTTP API, что и здесь, см. подробности и
 * грабли в /home/claude/max/ЗАМЕТКИ.md. Саму эту PHP-функцию посимвольно
 * не гонял — на сервере, где идёт разработка, нет PHP; сработает на первом
 * реальном вызове с боевого хостинга. Если платформа всё же отклонит поле
 * "link" — код ниже сам откатится на отправку без привязки к сообщению.
 *
 * Не бросает исключения наружу — ошибка отправки в MAX не должна ломать
 * сохранение проверки на сайте.
 */
function max_send_reply(string $chatId, string $text, ?string $replyToMessageId = null): bool
{
    if (!defined('MAX_BOT_TOKEN') || MAX_BOT_TOKEN === '') {
        error_log('max_send_reply: MAX_BOT_TOKEN не задан в config.php');
        return false;
    }

    $attempts = [];
    if ($replyToMessageId !== null && $replyToMessageId !== '') {
        $attempts[] = ['text' => $text, 'link' => ['type' => 'reply', 'mid' => $replyToMessageId]];
    }
    $attempts[] = ['text' => $text];

    foreach ($attempts as $body) {
        if (max_api_request('/messages', ['chat_id' => $chatId], $body)) {
            return true;
        }
    }

    return false;
}

/** Один запрос к MAX Bot API. true — если ответ 2xx. */
function max_api_request(string $path, array $query, array $jsonBody): bool
{
    $url = rtrim(MAX_API_BASE, '/') . $path . '?' . http_build_query($query);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($jsonBody, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: ' . MAX_BOT_TOKEN],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CAINFO => defined('MAX_CA_BUNDLE') ? MAX_CA_BUNDLE : null,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        error_log(sprintf(
            'max_api_request: %s -> HTTP %d, curl_error="%s", body=%s',
            $path,
            $httpCode,
            $curlError,
            substr((string) $response, 0, 500)
        ));
        return false;
    }

    return true;
}
