<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SshKey extends Model
{
    protected $fillable = [
        'name',
        'public_key',
        'private_key',
        'fingerprint',
    ];

    protected $hidden = [
        'private_key',
    ];

    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
        ];
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(Node::class);
    }
}
