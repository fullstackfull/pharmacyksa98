<?php

namespace Tests\Feature\SalesChannels;

use App\Services\SalesChannels\ChannelAccountService;
use App\Services\SalesChannels\Support\ChannelOperations;
use App\Services\SalesChannels\Support\UncalledOperations;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

/**
 * ONE guard over every channel, on the tree where all five exist together.
 *
 * ## Why this had to be one test and not five
 *
 * The two defects the trial integration branch found were each caught by a guard that already existed — **on another
 * branch**. The scope `return_decisions` was demanded by two Trendyol operations and absent from the list a person can be
 * offered, so the approval could never be granted and no return could ever be sent; the guard that noticed lives in the Odoo
 * work. Nineteen green jobs on each branch separately said nothing about either.
 *
 * So the guards are gathered here, over the whole registry, and the registry spans every channel. A branch cannot be green
 * past this file and wrong in the merge for the same class of reason again.
 *
 * ## And the honest part
 *
 * A registration is a promise: a path, a method, the capabilities needed, the approval relied on. Twenty of the hundred and
 * five are never called by anything. `UncalledOperations` lists each with its reason and its kind, and this file checks the
 * list against a scan of the code **in both directions** — an operation that quietly stops being called fails, and one that
 * starts being called while still listed as a gap fails too. That is what keeps the gap matrix from drifting back into
 * saying an integration does something it merely could.
 */
class ChannelOperationHygieneTest extends TestCase
{
    use BuildsSalesChannelSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        ChannelOperations::flush();
    }

    public function test_every_registered_operation_is_either_called_or_declared_a_gap_with_a_reason(): void
    {
        $uncalled = $this->operationsNothingCalls();
        $declared = UncalledOperations::names();

        sort($uncalled);
        $expected = $declared;
        sort($expected);

        $this->assertSame(
            $expected,
            $uncalled,
            "The registry and the code disagree about what is actually called.\n"
            . "Nothing calls, and not declared in UncalledOperations: " . implode(', ', array_diff($uncalled, $declared)) . "\n"
            . 'Declared a gap, but something calls it now: ' . implode(', ', array_diff($declared, $uncalled)),
        );
    }

    public function test_every_declared_gap_names_a_kind_and_a_reason(): void
    {
        // "Not built" and "decided against" and "needs a real account" are not interchangeable, and the matrix blurred them.
        $kinds = [UncalledOperations::DECIDED, UncalledOperations::NOT_BUILT, UncalledOperations::NEEDS_AN_ACCOUNT, UncalledOperations::PARTLY_BUILT];

        foreach (UncalledOperations::all() as $operation => $entry) {
            $this->assertContains($entry['kind'], $kinds, "'{$operation}' has no recognised kind.");
            $this->assertNotSame('', trim($entry['why']), "'{$operation}' is a gap with no reason given.");
            $this->assertTrue(ChannelOperations::isRegistered($operation), "'{$operation}' is declared a gap and is not registered at all.");
        }
    }

    public function test_amazon_feeds_are_not_claimed_as_built_merely_because_their_pieces_exist(): void
    {
        // The submission document and the result reader are built and tested. **That is not the feature.** Five calls carry a
        // feed and none of them is made, so all five stay declared as partly built -- and the capability matrix keeps saying
        // `built => false`, which its own guard enforces.
        foreach (['amazon.feeds_create_document', 'amazon.feeds_upload', 'amazon.feeds_create', 'amazon.feeds_get', 'amazon.feeds_document'] as $operation) {
            $this->assertSame(UncalledOperations::PARTLY_BUILT, UncalledOperations::kindOf($operation), "'{$operation}' no longer says the feed is unfinished.");
        }
        $this->assertCount(5, UncalledOperations::ofKind(UncalledOperations::PARTLY_BUILT), 'the only partly-built thing here is the Amazon feed');
    }

    public function test_every_write_names_a_capability_and_an_approval_a_person_can_be_asked_for(): void
    {
        // The exact shape of the defect the integration branch found: an operation demanding an approval that nobody could
        // grant. Over the whole registry now, not one channel's worth of it.
        foreach (ChannelOperations::operationsOfKind(ChannelOperations::WRITE) as $operation) {
            $this->assertNotEmpty(ChannelOperations::capabilities($operation), "the write '{$operation}' names no capability.");
            foreach (ChannelOperations::scopes($operation) as $scope) {
                $this->assertTrue(
                    in_array($scope, ChannelAccountService::EXTRA_SCOPES, true) || $this->isACapabilityScope($scope),
                    "the write '{$operation}' demands the approval '{$scope}', which nobody can be asked for: it is neither an "
                    . 'approval a person is offered nor the name of a capability.',
                );
            }
        }
    }

    public function test_no_operation_is_registered_twice_or_named_for_a_channel_that_does_not_exist(): void
    {
        $registered = ChannelOperations::registered();
        $this->assertSame(array_values(array_unique($registered)), array_values($registered), 'an operation is registered twice.');

        $families = ['amazon', 'trendyol', 'noon', 'odoo', 'torod', 'm5azn', 'myfatoorah', 'internal'];
        foreach ($registered as $operation) {
            $family = explode('.', $operation)[0];
            $this->assertContains($family, $families, "'{$operation}' belongs to no known integration.");
        }
    }

    // ---- the scan ------------------------------------------------------------------------------------------------

    /**
     * The registered operations that no line of `app/` names a second time.
     *
     * A registration names the operation once, in the registry. Anything calling it names it again. So a single occurrence
     * means nothing calls it -- a coarse rule, and the right kind of coarse: it cannot miss a call, it can only be fooled by
     * an operation whose name is written twice in the registry, which the duplicate test above forbids.
     *
     * @return list<string>
     */
    private function operationsNothingCalls(): array
    {
        // `UncalledOperations` names every one of these operations, so counting it would make all twenty look called and the
        // guard would pass over anything. The declaration of a gap is not a call -- and nor is the registry entry itself.
        $ignore = ['UncalledOperations.php'];

        $haystack = '';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'))) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && !in_array($file->getFilename(), $ignore, true)) {
                $haystack .= file_get_contents($file->getPathname());
            }
        }

        $uncalled = [];
        foreach (ChannelOperations::registered() as $operation) {
            if (substr_count($haystack, "'" . $operation . "'") < 2) {
                $uncalled[] = $operation;
            }
        }

        return $uncalled;
    }

    private function isACapabilityScope(string $scope): bool
    {
        foreach (\App\Enums\SalesChannels\IntegrationCapability::values() as $capability) {
            if ($scope === $capability) {
                return true;
            }
        }

        return false;
    }
}
