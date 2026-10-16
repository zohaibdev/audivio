<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the query patterns §37-§40 introduced, and a storage-engine
 * correction.
 *
 * Each of these runs on a hot path added by those phases and currently
 * has only a single-column index to work with, so MySQL picks one and
 * filters the rest by hand. That is invisible on a dev database with
 * twenty audits and will not stay invisible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audits', function (Blueprint $table) {
            // ActiveAuditLimiter::activeCount() — `WHERE user_id = ? AND
            // status IN (...)`. Runs on *every* audit creation, inside the
            // lock that serializes creation for that owner, so it is the
            // one query that must not get slower as history grows.
            $table->index(['user_id', 'status'], 'audits_user_id_status_index');

            // Website::latestCompletedAudit() and the audit-history reads
            // — `WHERE website_id = ? AND status = 'completed'`. Runs once
            // per website on every "My Websites" listing.
            $table->index(['website_id', 'status'], 'audits_website_id_status_index');
        });

        Schema::table('audit_issues', function (Blueprint $table) {
            // WebsiteCardBuilder / WebsiteDetailBuilder severity rollups —
            // `WHERE audit_id IN (...) GROUP BY audit_id, severity`. There
            // was an (audit_id, category) index but nothing pairing
            // audit_id with severity, so the grouping read every issue row
            // for the audit.
            $table->index(['audit_id', 'severity'], 'audit_issues_audit_id_severity_index');
        });

        // Laravel's own `migrations` table predates this project's
        // convention of setting the engine explicitly, so it inherited the
        // server default — MyISAM on this box. Harmless in itself (no
        // foreign keys, no transactional writes), but it leaves the schema
        // mixed, and a mixed schema is where someone later assumes the
        // wrong thing. `config/database.php` now pins InnoDB so nothing
        // new can land this way.
        if (DB::getDriverName() === 'mysql') {
            $engine = DB::table('information_schema.TABLES')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', 'migrations')
                ->value('ENGINE');

            if ($engine !== null && strtoupper($engine) !== 'INNODB') {
                DB::statement('ALTER TABLE `migrations` ENGINE = InnoDB');
            }
        }
    }

    public function down(): void
    {
        // These two composite indexes are the only ones covering
        // `user_id` and `website_id`, so on MySQL InnoDB is using them to
        // satisfy the foreign keys and refuses the drop (error 1553).
        // Both constraints step aside and go back afterwards, which lets
        // MySQL rebuild its own single-column indexes for them.
        $isMysql = DB::getDriverName() === 'mysql';

        if ($isMysql) {
            Schema::table('audits', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropForeign(['website_id']);
            });
        }

        Schema::table('audits', function (Blueprint $table) {
            $table->dropIndex('audits_user_id_status_index');
            $table->dropIndex('audits_website_id_status_index');
        });

        if ($isMysql) {
            Schema::table('audits', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('website_id')->references('id')->on('websites')->cascadeOnDelete();
            });
        }

        Schema::table('audit_issues', function (Blueprint $table) {
            $table->dropIndex('audit_issues_audit_id_severity_index');
        });

        // The engine change is deliberately not reverted: putting a table
        // back on MyISAM is not something a rollback should ever do.
    }
};
