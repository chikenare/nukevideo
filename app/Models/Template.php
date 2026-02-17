<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Template extends Model
{
    protected $fillable = [
        'name',
        'query',
    ];

    protected function casts()
    {
        return [
            'query' => 'json'
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->ulid = (string) Str::ulid();
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeFindOrFailByUlid(Builder $query, $ulid)
    {
        return $query->where('ulid', $ulid)->firstOrFail();
    }
}
