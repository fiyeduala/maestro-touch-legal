<?php

namespace Tests\Feature\Communication;

use App\Domain\Documents\Documents;
use App\Domain\Identity\Role;
use App\Http\Controllers\Portal\PortalNotificationController;
use App\Models\PushSubscription;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\PortalUpdate;
use App\Notifications\StaffAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PracticeFixtures;
use Tests\TestCase;

class InAppNotificationsTest extends TestCase
{
    use PracticeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('confidential');
        $this->setUpPractice();
        config(['push.vapid.public_key' => '', 'push.vapid.private_key' => '']);
    }

    public function test_account_holders_get_bell_and_push_channels_but_email_only_addresses_do_not(): void
    {
        $alert = new StaffAlert('New enquiry', 'Ada Obi sent an enquiry.', '/admin/enquiries/1');

        $this->assertEqualsCanonicalizing(['mail', 'database', WebPushChannel::class], $alert->via($this->lawyer));
        $this->assertSame(['mail'], $alert->via(Notification::route('mail', 'someone@example.test')));
        $this->assertNotContains('mail', (new StaffAlert('x', 'y', '/admin', mail: false))->via($this->lawyer));
    }

    public function test_a_staff_alert_is_stored_for_the_bell_and_skips_push_without_keys(): void
    {
        PushSubscription::create(['user_id' => $this->lawyer->id, 'endpoint' => 'https://push.example.test/abc', 'p256dh' => 'k', 'auth' => 'a']);

        $this->lawyer->notify(new StaffAlert('New enquiry', 'Ada Obi sent an enquiry.', '/admin/enquiries/1', mail: false));

        $stored = $this->lawyer->notifications()->sole();
        $this->assertSame('New enquiry', $stored->data['title']);
        $this->assertSame(url('/admin/enquiries/1'), $stored->data['url']);
        // Without VAPID keys nothing is sent and nothing is thrown; the subscription is kept.
        $this->assertSame(1, PushSubscription::count());
    }

    public function test_the_portal_lists_opens_and_clears_notifications(): void
    {
        [, $contact] = $this->openMatter();
        $contact->notifications()->delete();
        $contact->notify(new PortalUpdate('A document is ready', 'Your memorandum is ready to download.', '/portal/documents'));
        $id = $contact->notifications()->sole()->id;

        $this->actingAs($contact)->get(route('portal.notifications'))->assertOk()->assertSee('A document is ready');
        $this->actingAs($contact)->get(route('portal.notifications.open', $id))->assertRedirect(url('/portal/documents'));
        $this->assertNotNull($contact->notifications()->sole()->read_at);

        [, $other] = $this->openMatter();
        $this->actingAs($other)->get(route('portal.notifications.open', $id))->assertNotFound();

        $contact->notify(new PortalUpdate('Another', 'Line', '/portal'));
        $this->actingAs($contact)->post(route('portal.notifications.read'))->assertRedirect(route('portal.notifications'));
        $this->assertSame(0, $contact->unreadNotifications()->count());
    }

    public function test_notification_links_never_leave_the_site(): void
    {
        $this->assertSame(url('/portal/documents'), PortalNotificationController::target('/portal/documents'));
        $this->assertSame(route('portal.home'), PortalNotificationController::target('//evil.example/x'));
        $this->assertSame(route('portal.home'), PortalNotificationController::target('https://evil.example/x'));
        $this->assertSame(route('portal.home'), PortalNotificationController::target('javascript:alert(1)'));
        $this->assertSame(route('portal.home'), PortalNotificationController::target(null));
        $this->assertSame(url('/portal'), PortalNotificationController::target(url('/portal')));
    }

    public function test_browser_subscriptions_are_saved_for_the_signed_in_user_only(): void
    {
        $payload = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc', 'p256dh' => 'BKey', 'auth' => 'secret'];
        $this->postJson(route('push.subscribe'), $payload)->assertUnauthorized();

        $this->actingAs($this->lawyer)->postJson(route('push.subscribe'), $payload)->assertSuccessful();
        $this->actingAs($this->lawyer)->postJson(route('push.subscribe'), $payload)->assertSuccessful();
        $this->assertSame(1, PushSubscription::where('user_id', $this->lawyer->id)->count());

        $this->actingAs($this->lawyer)->postJson(route('push.subscribe'), ['endpoint' => 'http://insecure.example/x', 'p256dh' => 'a', 'auth' => 'b'])
            ->assertUnprocessable();

        $this->actingAs($this->admin)->deleteJson(route('push.unsubscribe'), ['endpoint' => $payload['endpoint']]);
        $this->assertSame(1, PushSubscription::count(), 'Someone else cannot remove the subscription.');
        $this->actingAs($this->lawyer)->deleteJson(route('push.unsubscribe'), ['endpoint' => $payload['endpoint']])->assertSuccessful();
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_document_uploads_and_new_versions_alert_the_team_but_not_the_person_who_did_it(): void
    {
        Notification::fake();
        [$matter] = $this->openMatter();
        $officer = $this->teamMember($matter, Role::CaseOfficer);
        $docs = app(Documents::class);

        $doc = $docs->upload($matter, $this->pdf('draft.pdf'), ['title' => 'Memorandum', 'category' => 'correspondence'], $officer);
        Notification::assertSentTo($this->lawyer, StaffAlert::class, fn (StaffAlert $n) => str_contains($n->subject, 'Document added'));
        Notification::assertNotSentTo($officer, StaffAlert::class, fn (StaffAlert $n) => str_contains($n->subject, 'Document added'));

        $docs->addVersion($doc, $this->pdf('draft-2.pdf'), 'Second draft', $this->lawyer);
        Notification::assertSentTo($officer, StaffAlert::class, fn (StaffAlert $n) => str_contains($n->subject, 'Document updated'));
        Notification::assertNotSentTo($this->lawyer, StaffAlert::class, fn (StaffAlert $n) => str_contains($n->subject, 'Document updated'));
    }
}
