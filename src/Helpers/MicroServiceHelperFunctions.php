<?php

use Illuminate\Support\Facades\Log;

if (! function_exists('generate_key')) {
    function generate_key(): string
    {
        return \base64_encode(\random_bytes(32));
    }
}

if (! function_exists('request_passed_ssl_configuration')) {
    function request_passed_ssl_configuration() {
        abort_if(config('microservice.secure_requests_only') == 'true' && (! request()->secure()), 496, 'insecure request are forbidden. SSL certificate required');
    }
}

if (! function_exists('append_to_env_content')) {
    function append_to_env_content(string $envContent, string $envKey, string $envKeyValue = '')
    {
        $keyPosition = \strpos($envContent, "{$envKey}=");

        if ($keyPosition) {
            return change_env_key_value(envContent: $envContent, envKey: $envKey, envKeyValue: $envKeyValue);
        }

        return $envContent . "{$envKey}={$envKeyValue}\n";
    }
}

if (! function_exists('change_env_key_value')) {
    function change_env_key_value(string $envContent, string $envKey, string $envKeyValue = '')
    {
        $keyPosition = \strpos($envContent, "{$envKey}=");
        $endOfLinePosition = \strpos($envContent, "\n", $keyPosition);
        $oldValue = \substr($envContent, $keyPosition, $endOfLinePosition - $keyPosition);

        return ($keyPosition && $endOfLinePosition && $oldValue)
            ? \str_replace($oldValue, "{$envKey}={$envKeyValue}", $envContent)
            : append_to_env_content(envContent: $envContent, envKey: $envKey, envKeyValue: $envKeyValue);
    }
}
