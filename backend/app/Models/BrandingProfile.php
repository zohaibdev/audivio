<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An agency's own branding for exported reports (§48).
 *
 * Stored per user and kept regardless of the current plan: this is data
 * the user entered, and whether it is *applied* is a separate question
 * asked at render time by BrandingResolver. A downgrade should stop the
 * branding appearing, not delete it.
 */
class BrandingProfile extends Model
{
    protected $fillable = [
        'user_id',
        'brand_name',
        'logo_path',
        'contact_email',
        'contact_phone',
        'contact_website',
        'footer_text',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether there is anything here worth rendering. An empty profile is
     * the same as no profile: applying it would replace the product's own
     * branding with nothing at all.
     */
    public function hasContent(): bool
    {
        return collect([
            $this->brand_name,
            $this->logo_path,
            $this->contact_email,
            $this->contact_phone,
            $this->contact_website,
            $this->footer_text,
        ])->contains(fn ($value) => filled($value));
    }
}
