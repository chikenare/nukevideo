<?php

namespace App\Data\User;

use App\Data\RequestData;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Unique;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;

class StoreUserData extends RequestData
{
    public function __construct(
        #[Max(255)]
        public string $name,
        #[Email, Unique('users', 'email')]
        public string $email,
        #[Min(8)]
        public string $password,
        #[MapInputName(CamelCaseMapper::class)]
        public bool|Optional $isAdmin,
    ) {}
}
