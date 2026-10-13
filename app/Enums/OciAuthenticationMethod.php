<?php

namespace App\Enums;

enum OciAuthenticationMethod: string
{
    case INSTANCE_PRINCIPAL = 'instance_principal';
    case API_KEY = 'api_key';
}
