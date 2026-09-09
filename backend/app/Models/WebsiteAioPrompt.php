<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A custom AIO question an owner wants asked of their own site, on top of
 * the ten defaults in config/aio_prompts.php (see section 42).
 */
class WebsiteAioPrompt extends Model
{
    protected $fillable = ['website_id', 'prompt', 'sort_order'];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
