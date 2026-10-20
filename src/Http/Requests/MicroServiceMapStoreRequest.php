<?php

namespace Otas\MicroService\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use Otas\MicroService\Helpers\MsHttp;

class MicroServiceMapStoreRequest extends FormRequest
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
            'name' => [
                'required',
                'string',
                'alpha_dash:ascii',
                'min:3',
                'max:190',
                // Establishing again is allowed only from the same origin, so another server can't take this name.
                Rule::unique('micro_service_maps', 'name')->where(fn ($query) => $query->where('origin', '!=', $this->origin)),
            ],
            'origin' => 'required|string|min:6|max:190',
            'secret_key' => 'required|string|size:44',
            'access_key' => 'required|string|size:44',
        ];
    }

    public function storeMicroServiceMap(): array
    {
        $receiveKeys = [
            'secret_key' => generate_key(),
            'access_key' => generate_key(),
        ];

        MsHttp::saveMicroservice(
            microserviceName: $this->name,
            origin: $this->origin,
            receiveKeys: $receiveKeys,
            sendKeys: $this->only(['secret_key', 'access_key']),
        );

        return $receiveKeys;
    }
}
