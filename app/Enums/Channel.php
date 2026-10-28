<?php

namespace App\Enums;

enum Channel: string
{
    case Sms = 'sms';
    case Email = 'email';
    case Push = 'push';

    public function maxLength(): int
    {
        return config("notifications.max_length.{$this->value}");
    }

    public function isValidRecipient(string $recipient): bool
    {
        return match ($this) {
            self::Sms => (bool) preg_match('/^\+[1-9]\d{7,14}$/', $recipient),
            self::Email => (bool) filter_var($recipient, FILTER_VALIDATE_EMAIL),
            self::Push => strlen($recipient) >= 16,
        };
    }
}
