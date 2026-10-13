<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Seeders;

use Filagora\Entitlements\Models\EntitlementDefinition;
use Illuminate\Database\Seeder;

/**
 * Creates the definitions of the `operator.*` staff entitlements (keys and labels only, never
 * grants), for staging and test setups. Definitions that already exist are left as they are.
 */
class OperatorEntitlementSeeder extends Seeder
{
    /**
     * @var array<string, string>
     */
    public const LABELS = [
        'operator.dashboard.access' => 'Dashboard Access',
        'operator.queue_monitors.view' => 'Queue Monitors',
        'operator.forum.moderate' => 'Forum Moderation',
        'operator.forum.view_unapproved' => 'View Unapproved Forum Content',
        'operator.forum.bypass_approval' => 'Bypass Forum Approval',
        'operator.plugin.moderate' => 'Plugin Moderation',
        'operator.plugin.auto_publish' => 'Plugin Auto Publish',
        'operator.newsletter.manage' => 'Newsletter Management',
        'operator.cms.manage' => 'CMS Management',
        'operator.cms.preview' => 'CMS Draft Preview',
        'operator.users.view' => 'User Directory Access',
        'operator.users.update' => 'User Editing',
        'operator.users.act_as' => 'User Impersonation',
        'operator.privacy.requests.view' => 'Privacy Request Access',
        'operator.privacy.requests.update' => 'Privacy Request Updates',
        'operator.entitlements.view' => 'Entitlement Visibility',
        'operator.users.assign_operator_access' => 'Operator Access Management',
        'operator.privacy.requests.delete' => 'Privacy Request Deletion',
        'operator.entitlements.manage' => 'Entitlement Management',
        'operator.system.settings.manage' => 'System Settings',
        'operator.system.backups.manage' => 'Database Backups',
        'operator.audit.view' => 'Audit Access',
        'operator.cookie_policies.manage' => 'Cookie Policy Management',
        'operator.privacy_policies.manage' => 'Privacy Policy Management',
    ];

    public function run(): void
    {
        $order = 0;

        foreach (self::LABELS as $key => $label) {
            EntitlementDefinition::query()->firstOrCreate(
                ['key' => $key],
                ['label' => $label, 'namespace' => 'operator', 'sort_order' => $order += 10],
            );
        }
    }
}
