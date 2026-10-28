<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreNotificationRequest extends FormRequest
{
    use ValidatesNotifications;

    public function rules(): array
    {
        return $this->notificationRules();
    }

    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->checkChannelRules($validator, $this->all()),
        ];
    }
}
