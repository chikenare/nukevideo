<?php

namespace App\Data\Template;

use App\Data\RequestData;

class ReorderTemplatesData extends RequestData
{
    public function __construct(
        /** @var string[] */
        public array $ulids,
    ) {}

    public static function rules(): array
    {
        return [
            // The full list, in display order. Ownership is checked in the controller: a rule here
            // has no access to the resolved project, and reordering a ULID from another tenant
            // would be a cross-project write.
            'ulids' => 'required|array|min:1',
            'ulids.*' => 'required|ulid|distinct',
        ];
    }
}
