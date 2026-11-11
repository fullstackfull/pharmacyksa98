<?php

namespace Tests\Feature\SalesChannels;

use App\Services\Accounting\Odoo\OdooOperations;
use App\Services\SalesChannels\Attention\AttentionRegistry;
use App\Services\SalesChannels\Returns\ChannelReturnDecisions;
use App\Services\SalesChannels\Shipments\ChannelPackageSplits;
use App\Services\SalesChannels\Support\ChannelOperations;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

/**
 * **Nothing in this work does anything until somebody turns it on.**
 *
 * Running the migrations must not start a single external call, a single journal entry or a single refund. That is the one
 * property an owner can check before deploying and the hardest to be sure of by reading, because it is spread across four
 * config files, a per-account flag, two approval scopes and nine migrations.
 *
 * So it is asserted from the shipped defaults: the config files as they are in the repository, with no environment and no test
 * override. A switch that shipped on would fail here rather than in production.
 */
class EverythingStartsOffTest extends TestCase
{
    use BuildsSalesChannelSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        ChannelOperations::flush();
    }

    public function test_every_master_switch_ships_off(): void
    {
        // Read from the files themselves, not from the live config, because the test harness turns several of these on for
        // its own suites -- and what is being asserted is what a fresh installation gets.
        $channels = require base_path('config/sales_channels.php');
        $odoo = require base_path('config/odoo.php');

        $this->assertFalse((bool) $channels['enabled'], 'SALES_CHANNELS_ENABLED ships on');
        $this->assertFalse((bool) $channels['external_writes_enabled'], 'external channel writes ship on');
        $this->assertFalse((bool) $odoo['enabled'], 'ODOO_ENABLED ships on');
        $this->assertFalse((bool) $odoo['external_writes_enabled'], 'Odoo external writes ship on');
        $this->assertFalse((bool) $odoo['draft_creation_enabled'], 'Odoo draft creation ships on');
    }

    public function test_the_two_switch_families_are_independent(): void
    {
        // Turning the marketplaces on must not permit one line in somebody's books, and turning the books on must not permit
        // one price to be published. The families are separate on purpose and the registry is what keeps them so.
        $channelOperations = array_filter(ChannelOperations::registered(), static fn (string $operation): bool => !str_starts_with($operation, 'odoo.'));
        $odooOperations = array_filter(ChannelOperations::registered(), static fn (string $operation): bool => str_starts_with($operation, 'odoo.'));

        $this->assertNotEmpty($odooOperations);
        foreach ($channelOperations as $operation) {
            $this->assertSame(ChannelOperations::SWITCHES_SALES_CHANNELS, ChannelOperations::switchesOf($operation), "{$operation} reads the wrong switch family");
        }
        foreach ($odooOperations as $operation) {
            $this->assertSame(ChannelOperations::SWITCHES_ODOO, ChannelOperations::switchesOf($operation), "{$operation} reads the wrong switch family");
        }
    }

    public function test_posting_an_accounting_entry_is_not_even_registered(): void
    {
        // The irreversible step in somebody's real accounts. Odoo documents neither a method name for it nor writing `state`
        // directly, so it is not a switch that is off -- there is nothing to switch on.
        $refused = OdooOperations::deliberatelyNotRegistered();

        $this->assertArrayHasKey('odoo.post_entry', $refused);
        foreach (array_keys($refused) as $operation) {
            $this->assertFalse(ChannelOperations::isRegistered($operation), "{$operation} is registered after all");
            $this->assertNotSame('', trim($refused[$operation]), "{$operation} is refused with no reason");
        }
    }

    public function test_the_approvals_that_move_money_are_granted_by_a_person_and_nothing_else(): void
    {
        // Neither of these can be reached by a switch: a capability being on is not consent to refund a customer or to write
        // a line into a real ledger, and both are recorded against the admin who granted them.
        foreach ([ChannelOperations::SCOPE_RETURN_DECISIONS, ChannelOperations::SCOPE_ACCOUNTING_POSTING] as $scope) {
            $demanded = array_filter(
                ChannelOperations::operationsOfKind(ChannelOperations::WRITE),
                static fn (string $operation): bool => in_array($scope, ChannelOperations::scopes($operation), true),
            );
            $this->assertNotEmpty($demanded, "nothing demands '{$scope}', so the approval guards nothing");
            $this->assertContains($scope, \App\Services\SalesChannels\ChannelAccountService::EXTRA_SCOPES, "'{$scope}' cannot be granted by anybody");
        }
    }

    public function test_every_new_pipeline_says_so_instead_of_failing_when_its_table_is_absent(): void
    {
        // These tables ship dormant: the code is deployed before the migrations run, and a half-migrated host must report
        // rather than throw. Checked by dropping them, which is what such a host looks like.
        $this->assertTrue(ChannelReturnDecisions::ready());
        $this->assertTrue(ChannelPackageSplits::ready());

        Schema::drop('channel_return_decisions');
        Schema::drop('channel_package_splits');

        $this->assertFalse(ChannelReturnDecisions::ready());
        $this->assertFalse(ChannelPackageSplits::ready());
        // And the collector simply shows fewer sources rather than breaking the screen.
        $keys = array_map(static fn ($source): string => $source->key, app(AttentionRegistry::class)->all());
        $this->assertNotContains('return_decisions', $keys);
        $this->assertNotContains('package_splits', $keys);
    }

    public function test_the_scheduled_runs_carry_no_write_that_a_switch_does_not_gate(): void
    {
        // `channels:decisions` is the one scheduled command added by this work that can send anything at all, and it reads
        // the master switch before it looks at a single row.
        config(['sales_channels.enabled' => false]);

        $this->artisan('channels:decisions')->expectsOutputToContain('switched off')->assertSuccessful();
    }
}
