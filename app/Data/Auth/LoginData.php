<?php

namespace App\Data\Auth;

use App\Data\RequestData;
use Spatie\LaravelData\Attributes\Validation\Email;

class LoginData extends RequestData
{
    public function __construct(
        #[Email]
        public string $email,
        public string $password,
    ) {}
}
