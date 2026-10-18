<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Privacy-first analytics. Nothing here identifies a person: IP addresses and user agents
 * are never stored, visitors are a hash with a salt that is replaced (and deleted) every
 * 24 hours, and paths are stored without their query string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alfakit_analytics_sites', function (Blueprint $table): void {
            $table->id();
            // The tenant that owns the site (null: a panel-wide site).
            $table->string('tenant_type')->nullable();
            $table->string('tenant_id')->nullable();
            $table->string('name');
            // The hostname the site is served from; `domains` lists extra hostnames.
            $table->string('domain')->nullable();
            $table->json('domains')->nullable();
            // The public key the snippet sends; not a secret.
            $table->string('key', 32)->unique();
            $table->string('timezone', 64)->default('UTC');
            $table->boolean('is_active')->default(true);
            $table->json('goals')->nullable();
            // Shared, read-only dashboard link (signed; changing the token revokes old links).
            $table->boolean('is_shared')->default(false);
            $table->string('share_token', 64)->nullable();
            $table->timestamps();

            $table->index(['tenant_type', 'tenant_id']);
        });

        // One row: the salt of the current 24 hours. Older salts are deleted when it rotates.
        Schema::create('alfakit_analytics_salts', function (Blueprint $table): void {
            $table->id();
            $table->string('salt', 64);
            $table->timestamp('created_at')->index();
        });

        // Visits (sessions): consecutive hits of one visitor less than the session timeout apart.
        Schema::create('alfakit_analytics_visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('alfakit_analytics_sites')->cascadeOnDelete();
            $table->string('visitor', 16);
            // The local date (site timezone) the visit started on.
            $table->date('date');
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at');
            $table->unsignedInteger('duration')->default(0);
            $table->unsignedInteger('pageviews')->default(0);
            $table->unsignedInteger('events')->default(0);
            $table->boolean('is_bounce')->default(true);
            $table->string('hostname')->nullable();
            $table->string('entry_page', 512);
            $table->string('exit_page', 512);
            $table->string('source', 128)->nullable();
            $table->string('channel', 32)->nullable();
            $table->string('referrer', 512)->nullable();
            $table->string('utm_source', 128)->nullable();
            $table->string('utm_medium', 128)->nullable();
            $table->string('utm_campaign', 128)->nullable();
            $table->string('utm_content', 128)->nullable();
            $table->string('utm_term', 128)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('device', 16)->nullable();
            $table->string('browser', 32)->nullable();
            $table->string('os', 32)->nullable();

            $table->index(['site_id', 'date']);
            $table->index(['site_id', 'visitor', 'last_seen_at']);
            $table->index(['site_id', 'last_seen_at']);
        });

        // Page views (`name` = pageview) and custom events.
        Schema::create('alfakit_analytics_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('alfakit_analytics_sites')->cascadeOnDelete();
            $table->foreignId('visit_id')->constrained('alfakit_analytics_visits')->cascadeOnDelete();
            $table->string('visitor', 16);
            $table->string('name', 64);
            $table->date('date');
            $table->unsignedTinyInteger('hour');
            $table->timestamp('occurred_at');
            $table->string('pathname', 512);
            $table->string('hostname')->nullable();

            $table->index(['site_id', 'date', 'name']);
            $table->index(['visit_id', 'name']);
        });

        Schema::create('alfakit_analytics_event_properties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('alfakit_analytics_events')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('alfakit_analytics_sites')->cascadeOnDelete();
            $table->date('date');
            $table->string('event_name', 64);
            $table->string('key', 64);
            $table->string('value', 256);

            $table->index(['site_id', 'date', 'event_name', 'key']);
        });

        /*
         * Daily rollups written by `alfakit-analytics:aggregate`: one row per site, local
         * date, dimension and value. The row with an empty dimension holds the day's totals
         * (and marks the day as aggregated).
         */
        Schema::create('alfakit_analytics_daily', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('alfakit_analytics_sites')->cascadeOnDelete();
            $table->date('date');
            $table->string('dimension', 160);
            $table->string('value', 512);
            $table->unsignedInteger('visitors')->default(0);
            $table->unsignedInteger('visits')->default(0);
            $table->unsignedInteger('pageviews')->default(0);
            $table->unsignedInteger('events')->default(0);
            $table->unsignedInteger('bounces')->default(0);
            $table->unsignedBigInteger('duration')->default(0);

            $table->index(['site_id', 'dimension', 'date']);
        });

        // Snapshots of a top list being exported (read by the exporter, pruned after a day).
        Schema::create('alfakit_analytics_report_rows', function (Blueprint $table): void {
            $table->id();
            $table->uuid('report');
            $table->foreignId('site_id')->constrained('alfakit_analytics_sites')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('dimension', 160);
            $table->string('label', 512);
            $table->unsignedInteger('visitors')->default(0);
            $table->unsignedInteger('visits')->default(0);
            $table->unsignedInteger('pageviews')->default(0);
            $table->unsignedInteger('events')->default(0);
            $table->decimal('bounce_rate', 5, 2)->nullable();
            $table->unsignedInteger('visit_duration')->nullable();
            $table->decimal('conversion_rate', 5, 2)->nullable();
            $table->decimal('change', 8, 2)->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['report', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alfakit_analytics_report_rows');
        Schema::dropIfExists('alfakit_analytics_daily');
        Schema::dropIfExists('alfakit_analytics_event_properties');
        Schema::dropIfExists('alfakit_analytics_events');
        Schema::dropIfExists('alfakit_analytics_visits');
        Schema::dropIfExists('alfakit_analytics_salts');
        Schema::dropIfExists('alfakit_analytics_sites');
    }
};
