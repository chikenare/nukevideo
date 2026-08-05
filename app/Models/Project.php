<?php

namespace App\Models;

use App\Http\Middleware\ResolveProject;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * A project is tokenable: its API key authenticates as the project itself, and
 * {@see ResolveProject} hands the request back to the owning user.
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasApiTokens, HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->ulid = (string) Str::ulid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(Template::class);
    }
}
