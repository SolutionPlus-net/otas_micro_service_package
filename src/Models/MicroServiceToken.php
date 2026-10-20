<?php

namespace Otas\MicroService\Models;

use Illuminate\Database\Eloquent\Model;
use Otas\MicroService\Traits\HasPackageFactory;

class MicroServiceToken extends Model
{
    use HasPackageFactory;

    protected $table = 'microservice_tokens';

    protected $fillable = [
        'micro_service_map_id',

        'receive_secret_old_key',
        'receive_secret_current_key',
        'send_secret_key',

        'receive_access_old_key',
        'receive_access_current_key',
        'send_access_key',
    ];

    protected function casts(): array
    {
        return [
            'receive_secret_old_key' => 'encrypted',
            'receive_secret_current_key' => 'encrypted',
            'send_secret_key' => 'encrypted',

            'receive_access_old_key' => 'encrypted',
            'receive_access_current_key' => 'encrypted',
            'send_access_key' => 'encrypted',
        ];
    }

    ## Relations

    public function microServiceMap()
    {
        return $this->belongsTo(MicroServiceMap::class);
    }

    ## Getters & Setters

    ## Query Scope Methods

    ## Other Methods

    // The access key tells which secret key encrypted the request: the current one, or the old one before a renewal.
    public function receiveSecretKeyFor(string $accessKey): string|null
    {
        if (\hash_equals($this->receive_access_current_key, $accessKey)) {
            return $this->receive_secret_current_key;
        }

        if (\hash_equals($this->receive_access_old_key, $accessKey)) {
            return $this->receive_secret_old_key;
        }

        return null;
    }

    // The current keys become the old keys, so requests the other service sends before it receives the new keys still work.
    public function renewReceiveKeys(): array
    {
        $newReceiveKeys = [
            'secret_key' => generate_key(),
            'access_key' => generate_key(),
        ];

        $this->update([
            'receive_secret_old_key' => $this->receive_secret_current_key,
            'receive_secret_current_key' => $newReceiveKeys['secret_key'],

            'receive_access_old_key' => $this->receive_access_current_key,
            'receive_access_current_key' => $newReceiveKeys['access_key'],
        ]);

        return $newReceiveKeys;
    }

}
