<?php

namespace Otas\MicroService\Models;

use Illuminate\Database\Eloquent\Model;
use Otas\MicroService\Traits\HasPackageFactory;
use Otas\MicroService\Traits\HasTimezoneFields;

class MicroServiceMap extends Model
{
    use HasPackageFactory, HasTimezoneFields;

    protected $fillable = [
        'name',
        'display_name',

        'origin',

        'created_at',
        'updated_at',
    ];

    ## Relations

    public function token()
    {
        return $this->hasOne(MicroServiceToken::class);
    }

    ## Getters & Setters

    ## Query Scope Methods

    ## Other Methods

}
