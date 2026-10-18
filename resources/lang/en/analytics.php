<?php

declare(strict_types=1);

return [

    'navigation' => [
        'label' => 'Analytics',
    ],

    'page' => [
        'title' => 'Analytics',
        'filters_retention' => 'Filtered numbers cover the last :days days',
        'empty' => [
            'heading' => 'No sites yet',
            'description' => 'Add a site, put its snippet on your pages and visits will show up here.',
            'action' => 'Add a site',
        ],
    ],

    'actions' => [
        'more' => 'More actions',
    ],

    'periods' => [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        'month' => 'This month',
        'last_month' => 'Last month',
        'year' => 'This year',
        '12mo' => 'Last 12 months',
        'custom' => 'Custom range',
        'range' => ':from – :to',
        'from' => 'From',
        'to' => 'To',
        'apply' => 'Apply',
    ],

    'compare' => [
        'enable' => 'Compare',
        'disable' => 'Stop comparing',
        'change' => ':change vs previous period',
    ],

    'metrics' => [
        'current_visitors' => 'Visitors now',
        'current_visitors_description' => 'In the last minute|In the last :minutes minutes',
        'visitors' => 'Visitors',
        'visits' => 'Visits',
        'pageviews' => 'Page views',
        'events' => 'Events',
        'views_per_visit' => 'Views per visit',
        'bounce_rate' => 'Bounce rate',
        'visit_duration' => 'Visit duration',
        'conversion_rate' => 'Conversion rate',
    ],

    'duration' => [
        'seconds' => ':seconds sec',
        'minutes' => ':minutes min :seconds sec',
        'hours' => ':hours h :minutes min',
    ],

    'chart' => [
        'heading' => 'Visitors over time',
        'previous' => ':metric (previous period)',
    ],

    'panels' => [
        'pages' => 'Pages',
        'sources' => 'Sources',
        'campaigns' => 'Campaigns',
        'locations' => 'Locations',
        'devices' => 'Devices',
        'events' => 'Custom events',
        'goals' => 'Goals',
        'empty' => 'No data for this period',
    ],

    'dimensions' => [
        'page' => 'Top pages',
        'entry_page' => 'Entry pages',
        'exit_page' => 'Exit pages',
        'source' => 'Source',
        'channel' => 'Channel',
        'referrer' => 'Referrer',
        'utm_source' => 'UTM source',
        'utm_medium' => 'UTM medium',
        'utm_campaign' => 'UTM campaign',
        'utm_content' => 'UTM content',
        'utm_term' => 'UTM term',
        'country' => 'Country',
        'device' => 'Device',
        'browser' => 'Browser',
        'os' => 'Operating system',
        'event' => 'Event',
        'goal' => 'Goal',
        'property' => 'Property: :key',
    ],

    'values' => [
        'direct' => 'Direct / None',
        'none' => '(none)',
        'unknown' => 'Unknown',
    ],

    'devices' => [
        'desktop' => 'Desktop',
        'mobile' => 'Mobile',
        'tablet' => 'Tablet',
    ],

    'channels' => [
        'direct' => 'Direct',
        'organic_search' => 'Organic search',
        'paid' => 'Paid',
        'social' => 'Social',
        'email' => 'Email',
        'referral' => 'Referral',
        'ai' => 'AI assistants',
        'campaign' => 'Campaign',
    ],

    'filters' => [
        'is' => ':dimension is :value',
        'remove' => 'Remove filter',
    ],

    'export' => [
        'label' => 'Export top list',
        'heading' => 'Export a top list',
        'description' => 'The list is exported with the current period and filters. You will be notified when the file is ready.',
        'dimension' => 'List',
        'model' => 'top list row',
        'plural_model' => 'top list rows',
        'columns' => [
            'value' => 'Value',
            'visit_duration_seconds' => 'Visit duration (seconds)',
            'change' => 'Change (%)',
        ],
    ],

    'share' => [
        'label' => 'Share link',
        'heading' => 'Shared dashboard',
        'description' => 'Anyone with this link can see this site\'s dashboard, read-only. Turn sharing off or rotate the link to revoke it.',
        'link' => 'Link',
        'copied' => 'Link copied',
        'close' => 'Close',
        'rotate' => 'Rotate share link',
        'rotate_description' => 'Links handed out before stop working.',
    ],

    'shared' => [
        'title' => ':site analytics',
        'period' => 'Period',
        'summary' => 'Summary',
        'footer' => 'Privacy-friendly analytics: no cookies, no personal data.',
    ],

    'sites' => [
        'label' => 'site',
        'plural_label' => 'sites',
        'navigation' => 'Sites',
        'open_dashboard' => 'Dashboard',
        'copied' => 'Snippet copied',
        'key' => 'Site key: :key',
        'shared' => 'Shared',
        'private' => 'Private',
        'sections' => [
            'site' => 'Site',
            'site_description' => 'Hits from other hostnames are ignored.',
            'goals' => 'Goals',
            'goals_description' => 'Conversions: visitors who reached a page or triggered an event.',
            'tracking' => 'Tracking snippet',
            'tracking_description' => 'Add it to the <head> of your pages, or use @alfakitAnalytics in Blade or the AlfakitAnalytics component of your renderer.',
            'sharing' => 'Sharing',
            'sharing_description' => 'A signed, read-only link to this site\'s dashboard.',
        ],
        'fields' => [
            'name' => 'Name',
            'domain' => 'Domain',
            'domain_help' => 'Leave empty to accept any hostname. *.example.com accepts its subdomains.',
            'domains' => 'Other domains',
            'domains_help' => 'More hostnames that report to this site.',
            'timezone' => 'Timezone',
            'is_active' => 'Collect visits',
            'is_active_help' => 'Paused sites ignore new hits.',
            'is_shared' => 'Public link',
            'is_shared_help' => 'Anyone with the link can view the dashboard.',
            'key' => 'Site key',
            'status' => 'Status',
        ],
        'statuses' => [
            'active' => 'Active',
            'paused' => 'Paused',
        ],
        'goals' => [
            'add' => 'Add goal',
            'type' => 'Type',
            'types' => [
                'page' => 'Page visit',
                'event' => 'Custom event',
            ],
            'path' => 'Page path',
            'path_help' => 'Use * as a wildcard: /blog/*',
            'event' => 'Event name',
            'name' => 'Display name',
            'name_placeholder' => 'Optional',
        ],
        'empty' => [
            'heading' => 'No sites yet',
            'description' => 'Add the website or app whose visits you want to count.',
        ],
    ],

    // Frontend strings (`t('analytics.…')`), shared with the renderers.
    'ui' => [
        'views' => ':panel views',
        'filter_by' => 'Filter by :label',
        'remove_filter' => 'Remove filter :label',
        'change' => ':change vs previous period',
        'empty' => 'No data for this period',
    ],

];
