<?php

declare(strict_types=1);

namespace App\Http;

/** What a handler returns. Built by the static helpers, sent once by the front controller. */
final readonly class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $body,
        public int $status = 200,
        public array $headers = ['Content-Type' => 'text/html; charset=utf-8'],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new self($body, $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function redirect(string $to): self
    {
        return new self('', 303, ['Location' => $to]);
    }

    public function withStatus(int $status): self
    {
        return new self($this->body, $status, $this->headers);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        echo $this->body;
    }
}
