<?php

namespace Otas\MicroService\Http\Controllers;

use Otas\MicroService\Http\Controllers\Controller;
use Otas\MicroService\Http\Requests\MicroServiceKeysRenewRequest;

class MicroServiceKeysController extends Controller
{
    /**
     * Save the new keys the other service will receive with from now on.
     *
     * @param  Otas\MicroService\Http\Requests\MicroServiceKeysRenewRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function renew(MicroServiceKeysRenewRequest $request)
    {
        $request->renewSendKeys();

        return response([
            'message' => 'renewed',
        ]);
    }
}
