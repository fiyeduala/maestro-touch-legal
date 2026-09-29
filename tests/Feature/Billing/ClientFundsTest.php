<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\ClientFunds;
use App\Domain\RuleViolation;
use App\Models\ClientFundEntry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BillingFixtures;
use Tests\Concerns\PracticeFixtures;
use Tests\TestCase;

class ClientFundsTest extends TestCase
{
    use BillingFixtures;
    use PracticeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('confidential');
        $this->setUpPractice();
        $this->setUpBilling();
    }

    private function funds(): ClientFunds
    {
        return app(ClientFunds::class);
    }

    private function entry(array $data): array
    {
        return $data + ['currency' => 'NGN', 'occurred_on' => now()->toDateString(), 'description' => 'Recovered debt from Acme Ltd'];
    }

    public function test_outflows_need_authorisation_and_never_overdraw(): void
    {
        [$matter] = $this->openMatter();
        $client = $matter->client;

        $this->funds()->record($client, $matter, $this->entry(['type' => 'receipt', 'amount_minor' => 1_000_000, 'bank_reference' => 'FT123']), $this->pdf('credit-advice.pdf'), $this->finance);

        // No authorisation, no fee deduction.
        $this->assertThrows(fn () => $this->funds()->record($client, $matter, $this->entry(['type' => 'fee_deduction', 'amount_minor' => 100_000]), null, $this->finance), RuleViolation::class);
        $this->funds()->record($client, $matter, $this->entry(['type' => 'fee_deduction', 'amount_minor' => 100_000, 'authorisation' => 'Client email of today approving the 10% fee']), null, $this->finance);

        // Cannot pay out more than is held, and USD is a separate balance.
        $this->assertThrows(fn () => $this->funds()->record($client, $matter, $this->entry(['type' => 'remittance', 'amount_minor' => 900_001, 'authorisation' => 'Client instruction by email']), null, $this->finance), RuleViolation::class);
        $this->assertThrows(fn () => $this->funds()->record($client, $matter, $this->entry(['currency' => 'USD', 'type' => 'remittance', 'amount_minor' => 1, 'authorisation' => 'Client instruction by email']), null, $this->finance), RuleViolation::class);

        $this->assertSame(900_000, $this->funds()->balance($client->id, 'NGN'));
        $this->assertSame(0, $this->funds()->balance($client->id, 'USD'));
        $this->assertSame([1_000_000, 900_000], $this->funds()->statement($client->id, 'NGN')->pluck('running_balance_minor')->all());
        $this->assertNotNull(ClientFundEntry::where('type', 'receipt')->value('evidence_path'));
    }

    public function test_reversal_corrects_without_editing_and_reconciliation_shows_differences(): void
    {
        [$matter] = $this->openMatter();
        $client = $matter->client;
        $receipt = $this->funds()->record($client, $matter, $this->entry(['type' => 'receipt', 'amount_minor' => 500_000]), null, $this->finance);

        $reversal = $this->funds()->reverse($receipt, 'Entered against the wrong client', $this->finance);
        $this->assertSame(-500_000, $reversal->amount_minor);
        $this->assertSame(500_000, $receipt->refresh()->amount_minor); // original untouched
        $this->assertSame(0, $this->funds()->balance($client->id, 'NGN'));
        $this->assertThrows(fn () => $this->funds()->reverse($receipt, 'Again', $this->finance), RuleViolation::class);
        $this->assertThrows(fn () => $this->funds()->reverse($reversal, 'Undo the undo', $this->finance), RuleViolation::class);

        // Money already paid out cannot be reversed away.
        $second = $this->funds()->record($client, $matter, $this->entry(['type' => 'receipt', 'amount_minor' => 300_000]), null, $this->finance);
        $this->funds()->record($client, $matter, $this->entry(['type' => 'remittance', 'amount_minor' => 300_000, 'authorisation' => 'Client instruction of today']), null, $this->finance);
        $this->assertThrows(fn () => $this->funds()->reverse($second, 'Mistake', $this->finance), RuleViolation::class);

        $check = $this->funds()->reconcile('NGN', now()->toDateString(), 10_000, 'Bank charges not yet recorded', $this->finance);
        $this->assertSame(0, $check->ledger_balance_minor);
        $this->assertSame(10_000, $check->differenceMinor());
    }

    public function test_only_finance_records_and_lawyers_only_see_their_matters(): void
    {
        [$matter] = $this->openMatter();
        $this->assertThrows(fn () => $this->funds()->record($matter->client, $matter, $this->entry(['type' => 'receipt', 'amount_minor' => 1]), null, $this->lawyer), AuthorizationException::class);

        $entry = $this->funds()->record($matter->client, $matter, $this->entry(['type' => 'receipt', 'amount_minor' => 1]), null, $this->finance);
        $outsider = $this->userWithRoles(\App\Domain\Identity\Role::Lawyer);

        $this->assertTrue($this->lawyer->can('view', $entry));
        $this->assertFalse($outsider->can('view', $entry));
        $this->assertSame(0, ClientFundEntry::visibleTo($outsider)->count());
    }
}
