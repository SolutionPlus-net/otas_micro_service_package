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

}
