<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBatchRequest extends FormRequest
{
    use ValidatesNotifications;

    public function rules(): array
    {
        return [
            'notifications' => ['required', 'array', 'min:1', 'max:1000'],
            ...$this->notificationRules('notifications.*.'),
            'notifications.*.idempotency_key' => ['nullable', 'string', 'max:255', 'distinct'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach ((array) $this->input('notifications') as $i => $item) {
                    if (is_array($item)) {
                        $this->checkChannelRules($validator, $item, "notifications.{$i}.");
                    }
                }
            },
        ];
    }
}
