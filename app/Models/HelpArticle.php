<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class HelpArticle extends Model
{
    use BelongsToTenant;

    protected $fillable = ['title', 'slug', 'category', 'audience', 'body_html', 'published', 'updated_by'];

    protected function casts(): array
    {
        return ['published' => 'boolean'];
    }
}
