<?php

namespace Otas\MicroService\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Otas\MicroService\Helpers\MsHttp;

class MicroServiceKeysRenewRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'secret_key' => 'required|string|size:44',
            'access_key' => 'required|string|size:44',
        ];
    }

    public function renewSendKeys(): void
    {
        $token = MsHttp::current()->token()->first();

        $token->update([
            'send_secret_key' => $this->secret_key,
            'send_access_key' => $this->access_key,
        ]);
    }
}
