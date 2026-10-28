<?php

namespace App\Http\Requests;

use App\Enums\Channel;
use App\Enums\Priority;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesNotifications
{
    protected function notificationRules(string $prefix = ''): array
    {
        return [
            $prefix.'channel' => ['required', Rule::enum(Channel::class)],
            $prefix.'recipient' => ['required', 'string', 'max:255'],
            $prefix.'content' => ['required', 'string'],
            $prefix.'priority' => ['nullable', Rule::enum(Priority::class)],
            $prefix.'scheduled_at' => ['nullable', 'date', 'after:now'],
            $prefix.'idempotency_key' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function checkChannelRules(Validator $validator, array $item, string $prefix = ''): void
    {
        $channel = $item['channel'] ?? null;
        $recipient = $item['recipient'] ?? null;
        $content = $item['content'] ?? null;

        // wrong types are already reported by the rules above
        if (! is_string($channel) || ! is_string($recipient) || ! is_string($content)) {
            return;
        }

        $channel = Channel::tryFrom($channel);

        if (! $channel) {
            return;
        }

        if (! $channel->isValidRecipient($recipient)) {
            $message = match ($channel) {
                Channel::Sms => 'The recipient must be a phone number like +905551234567.',
                Channel::Email => 'The recipient must be a valid email address.',
                Channel::Push => 'The recipient must be a device token (at least 16 characters).',
            };
            $validator->errors()->add($prefix.'recipient', $message);
        }

        if (mb_strlen($content) > $channel->maxLength()) {
            $validator->errors()->add(
                $prefix.'content',
                "The content may not be longer than {$channel->maxLength()} characters for {$channel->value}."
            );
        }
    }
}
