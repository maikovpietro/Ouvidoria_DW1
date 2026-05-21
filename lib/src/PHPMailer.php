<?php
namespace PHPMailer\PHPMailer;

class PHPMailer
{
    public const ENCRYPTION_STARTTLS = 'tls';
    public const ENCRYPTION_SMTPS = 'ssl';

    public bool $SMTPAuth = false;
    public string $Host = '';
    public string $Username = '';
    public string $Password = '';
    public string $SMTPSecure = '';
    public int $Port = 0;
    public string $CharSet = 'UTF-8';
    public string $Subject = '';
    public string $Body = '';
    public string $AltBody = '';

    protected string $fromAddress = '';
    protected string $fromName = '';
    protected array $to = [];
    protected bool $isHtml = false;

    public function __construct(bool $exceptions = false)
    {
    }

    public function isSMTP(): void
    {
    }

    public function setFrom(string $address, string $name = ''): void
    {
        $this->fromAddress = $address;
        $this->fromName = $name;
    }

    public function addAddress(string $address, string $name = ''): void
    {
        $this->to[] = [$address, $name];
    }

    public function isHTML(bool $isHtml = true): void
    {
        $this->isHtml = $isHtml;
    }

    public function send(): bool
    {
        if (empty($this->to)) {
            return false;
        }

        $headers = [];
        $from = $this->fromName !== '' ? sprintf('%s <%s>', $this->fromName, $this->fromAddress) : $this->fromAddress;
        $headers[] = 'From: ' . $from;
        $headers[] = 'Reply-To: ' . $this->fromAddress;
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: ' . ($this->isHtml ? 'text/html' : 'text/plain') . '; charset=' . $this->CharSet;

        $destinos = array_map(fn($item) => $item[0], $this->to);
        $mensagem = $this->isHtml ? $this->Body : $this->AltBody;

        return @mail(implode(',', $destinos), $this->Subject, $mensagem, implode("\r\n", $headers));
    }
}
