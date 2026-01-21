<?php
/**
 * PIK Apartment Tracker - Email Notifications
 *
 * Simple mailer using PHP mail() or SMTP
 */

class Mailer
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config['email'] ?? [];
    }

    public function isEnabled(): bool
    {
        return !empty($this->config['enabled']) && !empty($this->config['to']);
    }

    /**
     * Send email notification
     */
    public function send(string $to, string $subject, string $body, bool $isHtml = true): bool
    {
        if (empty($to)) {
            return false;
        }

        $headers = [
            'MIME-Version: 1.0',
            'From: ' . ($this->config['from_name'] ?? 'PIK Tracker') . ' <' . ($this->config['from'] ?? 'noreply@localhost') . '>',
        ];

        if ($isHtml) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        }

        // Try SMTP if configured
        if (!empty($this->config['smtp']['host'])) {
            return $this->sendViaSMTP($to, $subject, $body, $isHtml);
        }

        // Fall back to PHP mail()
        return mail($to, $subject, $body, implode("\r\n", $headers));
    }

    /**
     * Send via SMTP (basic implementation)
     */
    private function sendViaSMTP(string $to, string $subject, string $body, bool $isHtml): bool
    {
        $smtp = $this->config['smtp'];

        try {
            $port = $smtp['port'] ?? 587;
            $encryption = $smtp['encryption'] ?? 'tls';

            $socket = $encryption === 'ssl'
                ? @fsockopen('ssl://' . $smtp['host'], $port, $errno, $errstr, 30)
                : @fsockopen($smtp['host'], $port, $errno, $errstr, 30);

            if (!$socket) {
                error_log("SMTP connection failed: $errstr ($errno)");
                return false;
            }

            stream_set_timeout($socket, 30);

            // Read greeting
            $this->smtpRead($socket);

            // EHLO
            $this->smtpSend($socket, "EHLO localhost");

            // STARTTLS if needed
            if ($encryption === 'tls') {
                $this->smtpSend($socket, "STARTTLS");
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $this->smtpSend($socket, "EHLO localhost");
            }

            // Auth
            if (!empty($smtp['username'])) {
                $this->smtpSend($socket, "AUTH LOGIN");
                $this->smtpSend($socket, base64_encode($smtp['username']));
                $this->smtpSend($socket, base64_encode($smtp['password']));
            }

            // Mail
            $from = $this->config['from'] ?? 'noreply@localhost';
            $this->smtpSend($socket, "MAIL FROM:<$from>");
            $this->smtpSend($socket, "RCPT TO:<$to>");
            $this->smtpSend($socket, "DATA");

            // Headers and body
            $contentType = $isHtml ? 'text/html' : 'text/plain';
            $message = "From: {$this->config['from_name']} <$from>\r\n";
            $message .= "To: $to\r\n";
            $message .= "Subject: $subject\r\n";
            $message .= "MIME-Version: 1.0\r\n";
            $message .= "Content-Type: $contentType; charset=UTF-8\r\n";
            $message .= "\r\n";
            $message .= $body;
            $message .= "\r\n.";

            $this->smtpSend($socket, $message);
            $this->smtpSend($socket, "QUIT");

            fclose($socket);
            return true;

        } catch (Exception $e) {
            error_log("SMTP error: " . $e->getMessage());
            return false;
        }
    }

    private function smtpSend($socket, string $data): string
    {
        fwrite($socket, $data . "\r\n");
        return $this->smtpRead($socket);
    }

    private function smtpRead($socket): string
    {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
        return $response;
    }

    /**
     * Build notification email for new apartments
     */
    public function buildNewApartmentsEmail(array $apartments, string $filterName = ''): string
    {
        $count = count($apartments);
        $title = $filterName ? "Новые квартиры: $filterName" : "Найдены новые квартиры";

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .header { background: #ff6b35; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; }
        .apartment { border: 1px solid #ddd; border-radius: 8px; margin: 15px 0; padding: 15px; }
        .price { font-size: 24px; font-weight: bold; color: #ff6b35; }
        .params { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin: 10px 0; }
        .param-label { font-size: 12px; color: #888; }
        .param-value { font-weight: bold; }
        .link { color: #ff6b35; text-decoration: none; }
        .footer { text-align: center; padding: 20px; color: #888; font-size: 12px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>$title</h1>
        <p>Найдено квартир: $count</p>
    </div>
    <div class="content">
HTML;

        foreach ($apartments as $apt) {
            $rooms = $apt['rooms'] === 0 ? 'Студия' : $apt['rooms'] . '-комн.';
            $price = number_format($apt['price'], 0, '', ' ') . ' ₽';
            $pricePerMeter = $apt['price_per_meter']
                ? number_format($apt['price_per_meter'], 0, '', ' ') . ' ₽/м²'
                : '';

            $html .= <<<HTML
        <div class="apartment">
            <div class="price">$price</div>
            <div style="color:#666;">$pricePerMeter</div>
            <div class="params">
                <div>
                    <div class="param-label">Комнаты</div>
                    <div class="param-value">$rooms</div>
                </div>
                <div>
                    <div class="param-label">Площадь</div>
                    <div class="param-value">{$apt['area']} м²</div>
                </div>
                <div>
                    <div class="param-label">Этаж</div>
                    <div class="param-value">{$apt['floor']}</div>
                </div>
                <div>
                    <div class="param-label">Сдача</div>
                    <div class="param-value">{$apt['settlement_date']}</div>
                </div>
            </div>
HTML;
            if (!empty($apt['url'])) {
                $html .= '<a href="' . $apt['url'] . '" class="link">Открыть на сайте PIK →</a>';
            }
            $html .= '</div>';
        }

        $html .= <<<HTML
    </div>
    <div class="footer">
        PIK Apartment Tracker | Автоматическое уведомление
    </div>
</body>
</html>
HTML;

        return $html;
    }

    /**
     * Build notification email for price changes
     */
    public function buildPriceChangeEmail(array $changes): string
    {
        $count = count($changes);

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .header { background: #f39c12; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; }
        .apartment { border: 1px solid #ddd; border-radius: 8px; margin: 15px 0; padding: 15px; }
        .price-old { text-decoration: line-through; color: #888; }
        .price-new { font-size: 24px; font-weight: bold; color: #ff6b35; }
        .price-diff { padding: 5px 10px; border-radius: 4px; display: inline-block; margin-left: 10px; }
        .price-down { background: #d4edda; color: #155724; }
        .price-up { background: #f8d7da; color: #721c24; }
        .link { color: #ff6b35; text-decoration: none; }
        .footer { text-align: center; padding: 20px; color: #888; font-size: 12px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Изменения цен</h1>
        <p>Квартир с изменением цены: $count</p>
    </div>
    <div class="content">
HTML;

        foreach ($changes as $change) {
            $apt = $change['apartment'];
            $oldPrice = number_format($change['old_price'], 0, '', ' ') . ' ₽';
            $newPrice = number_format($change['new_price'], 0, '', ' ') . ' ₽';
            $diff = $change['new_price'] - $change['old_price'];
            $diffFormatted = ($diff > 0 ? '+' : '') . number_format($diff, 0, '', ' ') . ' ₽';
            $diffClass = $diff < 0 ? 'price-down' : 'price-up';
            $rooms = $apt['rooms'] === 0 ? 'Студия' : $apt['rooms'] . '-комн.';

            $html .= <<<HTML
        <div class="apartment">
            <div>
                <span class="price-old">$oldPrice</span>
                <span class="price-new">$newPrice</span>
                <span class="price-diff $diffClass">$diffFormatted</span>
            </div>
            <div style="margin-top:10px;">
                <strong>$rooms</strong>, {$apt['area']} м², этаж {$apt['floor']}
            </div>
HTML;
            if (!empty($apt['url'])) {
                $html .= '<div style="margin-top:10px;"><a href="' . $apt['url'] . '" class="link">Открыть на сайте PIK →</a></div>';
            }
            $html .= '</div>';
        }

        $html .= <<<HTML
    </div>
    <div class="footer">
        PIK Apartment Tracker | Автоматическое уведомление
    </div>
</body>
</html>
HTML;

        return $html;
    }
}
