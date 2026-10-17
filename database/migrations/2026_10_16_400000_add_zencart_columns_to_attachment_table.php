<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What zen-cart.com's `post_attachments` table carries beyond the generic package: the ids and
 * state of the vBulletin attachment, file, post and private message each row was imported from,
 * placed where the live table has them. The live table also keeps its lookups by post and by
 * thread together with the old attachment id, which replace the plain `post_id` and `thread_id`
 * indexes the package creates for its foreign keys (MariaDB drops those itself once a composite
 * index covers the column). A separate migration, dated after the forums
 * package's attachments migration and after `filagora-messages` links its own columns, both of
 * which have to run first.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = Tables::name('post_attachments');

        Schema::table($table, function (Blueprint $table): void {
            $table->unsignedBigInteger('legacy_attachment_id')->nullable()->after('id');
            $table->unsignedBigInteger('legacy_filedata_id')->nullable()->after('legacy_attachment_id');
            $table->unsignedBigInteger('legacy_post_id')->nullable()->after('legacy_filedata_id');
            $table->unsignedBigInteger('legacy_pmtext_id')->nullable()->after('legacy_post_id');
            $table->string('legacy_state', 32)->nullable()->after('thumbnail_storage_path');
            $table->unsignedInteger('legacy_download_count')->default(0)->after('legacy_state');
            $table->dateTime('legacy_created_at')->nullable()->after('download_count');

            $table->unique('legacy_attachment_id');
            $table->index('legacy_filedata_id');
            $table->index('legacy_post_id');
            $table->index('legacy_pmtext_id');
            $table->index(['post_id', 'legacy_attachment_id'], Tables::name('post_attachments_post_id_legacy_attachment_id_index'));
            $table->index(['thread_id', 'legacy_attachment_id'], Tables::name('post_attachments_thread_id_legacy_attachment_id_index'));
        });
    }

    public function down(): void
    {
        $table = Tables::name('post_attachments');

        Schema::table($table, function (Blueprint $table): void {
            $table->index('post_id', Tables::name('post_attachments_post_id_foreign'));
            $table->index('thread_id', Tables::name('post_attachments_thread_id_foreign'));
        });

        Schema::table($table, function (Blueprint $table): void {
            $table->dropIndex(Tables::name('post_attachments_post_id_legacy_attachment_id_index'));
            $table->dropIndex(Tables::name('post_attachments_thread_id_legacy_attachment_id_index'));
            $table->dropUnique(['legacy_attachment_id']);
            $table->dropIndex(['legacy_filedata_id']);
            $table->dropIndex(['legacy_post_id']);
            $table->dropIndex(['legacy_pmtext_id']);
            $table->dropColumn(['legacy_attachment_id', 'legacy_filedata_id', 'legacy_post_id', 'legacy_pmtext_id', 'legacy_state', 'legacy_download_count', 'legacy_created_at']);
        });
    }
};
