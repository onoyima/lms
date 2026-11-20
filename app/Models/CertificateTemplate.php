<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A certificate or badge design, with the pass mark that earns it. */
class CertificateTemplate extends Model
{
    use BelongsToTenant;

    public const ICONS = ['award', 'medal', 'star', 'shield', 'book', 'trophy'];

    protected $fillable = ['name', 'kind', 'heading', 'body', 'signatory_name', 'signatory_title', 'colour', 'badge_icon', 'min_percent', 'auto_award', 'created_by'];

    protected function casts(): array
    {
        return ['auto_award' => 'boolean', 'min_percent' => 'float'];
    }
}
