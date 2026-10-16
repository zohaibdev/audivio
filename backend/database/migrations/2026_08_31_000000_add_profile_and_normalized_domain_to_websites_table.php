<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns `websites` from "one row per audit submission" into "one row per
 * domain per user", and gives it the profile fields a website record
 * needs to be a thing users manage rather than a byproduct of running an
 * audit.
 *
 * The dedup step in the middle is the whole reason this migration is more
 * than an ALTER: every audit run so far created its own `websites` row, so
 * an existing database has duplicates that the new unique index would
 * reject. They are merged rather than deleted — the audits hanging off
 * them are real history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            // `url` was always the URL the crawl starts from, not "the
            // website's URL" in any general sense. Now that a website is a
            // durable record with a domain identity of its own, the name
            // has to say which one it is.
            $table->renameColumn('url', 'primary_url');
        });

        Schema::table('websites', function (Blueprint $table) {
            // The dedup key. Separate from `domain` because `domain` is
            // what the crawler compares against for same-site checks (it
            // must stay exactly as the site serves it, www and all), while
            // this is the identity two submissions of "the same site" are
            // supposed to collapse onto.
            $table->string('normalized_domain')->nullable()->after('domain');

            $table->string('name')->nullable()->after('user_id');
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->text('products_or_services')->nullable();
            $table->string('country', 2)->nullable();
            $table->decimal('budget', 12, 2)->nullable();
            $table->decimal('average_product_cost', 12, 2)->nullable();
            $table->json('competitors')->nullable();
        });

        $this->backfillNormalizedDomains();
        $this->mergeDuplicateWebsites();

        Schema::table('websites', function (Blueprint $table) {
            $table->index('normalized_domain');

            // Scoped to the user, not global: two different users auditing
            // the same domain each get their own record, with their own
            // profile fields and their own audit history. A NULL user_id
            // (anonymous submission) is distinct from every other row under
            // SQL's NULL semantics, which is deliberate — see
            // WebsiteResolver for why anonymous submissions must not merge.
            $table->unique(['user_id', 'normalized_domain']);
        });
    }

    /**
     * Mirrors App\Services\Crawler\UrlNormalizer::normalizedDomain(),
     * inlined rather than called: a migration has to keep producing the
     * same result for the rows it already wrote, even if that method's
     * rules change later.
     */
    private function normalize(?string $domain): string
    {
        $domain = strtolower(trim((string) $domain));
        $domain = rtrim($domain, '.');

        if (str_starts_with($domain, 'www.')) {
            $domain = substr($domain, 4);
        }

        return $domain;
    }

    private function backfillNormalizedDomains(): void
    {
        DB::table('websites')->orderBy('id')->chunkById(200, function ($websites) {
            foreach ($websites as $website) {
                DB::table('websites')
                    ->where('id', $website->id)
                    ->update([
                        'normalized_domain' => $this->normalize($website->domain),
                        // A website with no name shows its domain; storing
                        // that up front means the listing never has to
                        // decide what to display for an older row.
                        'name' => $website->name ?? $this->normalize($website->domain),
                    ]);
            }
        });
    }

    /**
     * Collapse each (user_id, normalized_domain) group onto its earliest
     * row, repointing everything that referenced the duplicates. The
     * earliest row wins so the website's `created_at` stays honest: it is
     * when the user first audited that domain.
     *
     * Anonymous rows (user_id IS NULL) are left alone — they are not
     * deduplicated going forward either, so merging them here would
     * retroactively hand every anonymous audit of a domain to whoever
     * happens to hold the surviving row's id.
     */
    private function mergeDuplicateWebsites(): void
    {
        $groups = DB::table('websites')
            ->select('user_id', 'normalized_domain', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as total'))
            ->whereNotNull('user_id')
            ->groupBy('user_id', 'normalized_domain')
            ->having('total', '>', 1)
            ->get();

        foreach ($groups as $group) {
            $duplicateIds = DB::table('websites')
                ->where('user_id', $group->user_id)
                ->where('normalized_domain', $group->normalized_domain)
                ->where('id', '!=', $group->keep_id)
                ->pluck('id');

            if ($duplicateIds->isEmpty()) {
                continue;
            }

            DB::table('audits')->whereIn('website_id', $duplicateIds)->update(['website_id' => $group->keep_id]);
            DB::table('usage_logs')->whereIn('website_id', $duplicateIds)->update(['website_id' => $group->keep_id]);

            // audit_schedules carries its own unique (user_id, website_id).
            // Repointing a duplicate's schedule onto the surviving website
            // would collide with the survivor's own schedule, so only the
            // first is moved and the rest are dropped — one website has one
            // schedule by definition, and they are all for the same site.
            $scheduleIds = DB::table('audit_schedules')
                ->whereIn('website_id', $duplicateIds)
                ->orderBy('id')
                ->pluck('id');

            $survivorHasSchedule = DB::table('audit_schedules')
                ->where('website_id', $group->keep_id)
                ->exists();

            $keepScheduleId = $survivorHasSchedule ? null : $scheduleIds->first();

            if ($keepScheduleId !== null) {
                DB::table('audit_schedules')->where('id', $keepScheduleId)->update(['website_id' => $group->keep_id]);
            }

            DB::table('audit_schedules')
                ->whereIn('website_id', $duplicateIds)
                ->when($keepScheduleId !== null, fn ($q) => $q->where('id', '!=', $keepScheduleId))
                ->delete();

            DB::table('websites')->whereIn('id', $duplicateIds)->delete();
        }
    }

    public function down(): void
    {
        // On MySQL this unique index is the only one covering `user_id`,
        // so InnoDB is using it to satisfy the foreign key and refuses to
        // drop it (error 1553). The constraint has to step aside and be
        // put back; re-adding it lets MySQL create its own index again.
        // SQLite has no such requirement, which is why this rollback
        // looked fine for months.
        if (DB::getDriverName() === 'mysql') {
            Schema::table('websites', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
        }

        Schema::table('websites', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'normalized_domain']);
            $table->dropIndex(['normalized_domain']);
            $table->dropColumn([
                'normalized_domain',
                'name',
                'category',
                'description',
                'products_or_services',
                'country',
                'budget',
                'average_product_cost',
                'competitors',
            ]);
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('websites', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        Schema::table('websites', function (Blueprint $table) {
            $table->renameColumn('primary_url', 'url');
        });
    }
};
