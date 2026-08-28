<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Real bidirectional issue<->page relation, replacing the
        // meta.affected_pages JSON list as the queryable source for any
        // issue that spans more than one page (a single-page issue still
        // just uses audit_issues.audit_page_id directly — no pivot row
        // needed for that case). Supersedes the JSON-only approach: an
        // issue must be queryable by page and a page must be queryable by
        // issue, which a JSON blob can't do without a full table scan.
        Schema::create('issue_pages', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('audit_issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_page_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['audit_issue_id', 'audit_page_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_pages');
    }
};
