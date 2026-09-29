<?php

use App\Http\Controllers\Admin\BillingEvidenceController;
use App\Http\Controllers\Admin\StaffApplicationFileController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\DocumentFileController;
use App\Http\Controllers\Portal\PortalAppointmentController;
use App\Http\Controllers\Portal\PortalBillingController;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\Portal\PortalConversationController;
use App\Http\Controllers\Portal\PortalWorkController;
use App\Http\Controllers\Site\CareersController;
use App\Http\Controllers\Site\CommentController;
use App\Http\Controllers\Site\EnquiryController;
use App\Http\Controllers\Site\FeedController;
use App\Http\Controllers\Site\SiteController;
use App\Http\Controllers\Webhooks\PaystackWebhookController;
use App\Http\Middleware\AddTrailingSlash;
use App\Http\Middleware\EnsureActiveClient;
use App\Http\Middleware\EnsureStaffSessionVerified;
use Illuminate\Support\Facades\Route;

/*
| Public URLs keep the WordPress form ("/about/"); AddTrailingSlash 301s the bare form.
| The "/{slug}/" catch-all (pages, then posts) must stay last.
*/

// Paystack events: signature-checked in the controller; exempt from CSRF in bootstrap/app.php.
Route::post('/webhooks/paystack', PaystackWebhookController::class)->middleware('throttle:120,1')->name('webhooks.paystack');
Route::get('/robots.txt', [FeedController::class, 'robots']);
Route::get('/sitemap.xml', [FeedController::class, 'sitemap'])->name('sitemap');

// Confidential staff downloads: a verified staff-panel session plus the policy check in the controller.
Route::middleware(['auth', EnsureStaffSessionVerified::class])->prefix('admin/download')->group(function () {
    Route::get('/application-files/{file}', StaffApplicationFileController::class)->name('admin.application-file');
    Route::get('/documents/{version}', [DocumentFileController::class, 'staff'])->name('admin.document-file');
    Route::get('/payments/{payment}/evidence', [BillingEvidenceController::class, 'payment'])->name('admin.payment-evidence');
    Route::get('/client-funds/{entry}/evidence', [BillingEvidenceController::class, 'fundEntry'])->name('admin.fund-evidence');
});

// Staff previews of unpublished content (never cached, noindex).
Route::middleware(['auth', EnsureStaffSessionVerified::class])->prefix('preview')->group(function () {
    Route::get('/pages/{page}/{revision?}', [SiteController::class, 'previewPage'])->name('preview.page');
    Route::get('/posts/{post}', [SiteController::class, 'previewPost'])->name('preview.post');
});

// Client portal (Phase 2 shell).
Route::middleware(['auth', 'verified', EnsureActiveClient::class])->prefix('portal')->name('portal.')->group(function () {
    Route::get('/', [PortalController::class, 'home'])->name('home');
    Route::get('/documents/{version}', [DocumentFileController::class, 'client'])->name('document-file');
    Route::get('/matters/{matter}', [PortalWorkController::class, 'matter'])->name('matters.show');
    Route::post('/matters/{matter}/documents', [PortalWorkController::class, 'upload'])->middleware('throttle:20,1')->name('matters.upload');
    Route::post('/deliverables/{document}/decision', [PortalWorkController::class, 'decideDocument'])->middleware('throttle:auth-forms')->name('documents.decide');
    Route::get('/quotations/{quotation}', [PortalWorkController::class, 'quotation'])->name('quotations.show');
    Route::post('/quotations/{quotation}/respond', [PortalWorkController::class, 'respondQuotation'])->middleware('throttle:auth-forms')->name('quotations.respond');
    Route::get('/engagements/{engagement}', [PortalWorkController::class, 'engagement'])->name('engagements.show');
    Route::post('/engagements/{engagement}/respond', [PortalWorkController::class, 'respondEngagement'])->middleware('throttle:auth-forms')->name('engagements.respond');
    Route::get('/messages', [PortalConversationController::class, 'index'])->name('messages');
    Route::get('/matters/{matter}/messages', [PortalConversationController::class, 'poll'])->middleware('throttle:60,1')->name('matters.messages.poll');
    Route::post('/matters/{matter}/messages', [PortalConversationController::class, 'store'])->middleware('throttle:20,1')->name('matters.messages.store');
    Route::get('/invoices', [PortalBillingController::class, 'index'])->name('invoices');
    Route::get('/invoices/{invoice}', [PortalBillingController::class, 'show'])->name('invoices.show');
    Route::post('/invoices/{invoice}/pay', [PortalBillingController::class, 'pay'])->middleware('throttle:10,1')->name('invoices.pay');
    Route::post('/invoices/{invoice}/transfer', [PortalBillingController::class, 'transfer'])->middleware('throttle:10,1')->name('invoices.transfer');
    Route::get('/payments/callback', [PortalBillingController::class, 'callback'])->middleware('throttle:30,1')->name('payments.callback');
    Route::get('/payments/{payment}', [PortalBillingController::class, 'receipt'])->whereNumber('payment')->name('payments.show');
    Route::get('/funds', [PortalBillingController::class, 'funds'])->name('funds');
    Route::get('/appointments', [PortalAppointmentController::class, 'index'])->name('appointments');
    Route::post('/appointments', [PortalAppointmentController::class, 'store'])->middleware('throttle:auth-forms')->name('appointments.store');
    Route::post('/appointments/{consultation}/reschedule', [PortalAppointmentController::class, 'reschedule'])->middleware('throttle:auth-forms')->name('appointments.reschedule');
    Route::post('/appointments/{consultation}/cancel', [PortalAppointmentController::class, 'cancel'])->middleware('throttle:auth-forms')->name('appointments.cancel');
    Route::get('/profile', [PortalController::class, 'profile'])->name('profile');
    Route::put('/profile/recaps', [PortalController::class, 'updateRecaps'])->name('profile.recaps');
    Route::put('/profile', [PortalController::class, 'updateProfile'])->name('profile.update');
    Route::put('/profile/password', [PortalController::class, 'updatePassword'])->middleware('throttle:auth-forms')->name('password.update');
    Route::delete('/profile/sessions', [PortalController::class, 'logoutOtherSessions'])->middleware('throttle:auth-forms')->name('sessions.destroy');
});

Route::middleware('auth')->group(function () {
    Route::get('/email/verify', [VerifyEmailController::class, 'notice'])->name('verification.notice');
    Route::post('/email/verification-notification', [VerifyEmailController::class, 'send'])->middleware('throttle:6,1')->name('verification.send');
});
Route::get('/email/verify/{id}/{hash}', [VerifyEmailController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');

Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

// One-time links (tokens are hashed at rest).
Route::get('/invitation/{token}', [InvitationController::class, 'show'])->name('invitation.show');
Route::post('/invitation/{token}', [InvitationController::class, 'accept'])->middleware('throttle:auth-forms')->name('invitation.accept');
Route::get('/careers/application/{token}', [CareersController::class, 'show'])->name('careers.application.show');
Route::post('/careers/application/{token}/respond', [CareersController::class, 'respond'])->middleware('throttle:public-forms')->name('careers.application.respond');
Route::post('/careers/application/{token}/withdraw', [CareersController::class, 'withdraw'])->middleware('throttle:public-forms')->name('careers.application.withdraw');

Route::middleware(AddTrailingSlash::class)->group(function () {
    Route::get('/', [SiteController::class, 'home'])->name('home');

    Route::middleware('guest')->group(function () {
        Route::get('/log-in/', [LoginController::class, 'create'])->name('login');
        Route::post('/log-in/', [LoginController::class, 'store'])->middleware('throttle:auth-forms');
        Route::get('/register/', [RegisterController::class, 'create'])->name('register');
        Route::post('/register/', [RegisterController::class, 'store'])->middleware('throttle:public-forms');
        Route::get('/password-reset/', [PasswordController::class, 'request'])->name('password.request');
        Route::post('/password-reset/', [PasswordController::class, 'email'])->middleware('throttle:public-forms')->name('password.email');
        Route::get('/password-reset/{token}/', [PasswordController::class, 'edit'])->name('password.reset');
        Route::post('/password-reset/{token}/', [PasswordController::class, 'update'])->middleware('throttle:auth-forms')->name('password.store');
    });

    Route::get('/join-our-legal-team/', [CareersController::class, 'create'])->name('careers.apply');
    Route::post('/join-our-legal-team/', [CareersController::class, 'store'])->middleware('throttle:public-forms')->name('careers.store');
    Route::get('/join-our-legal-team/thank-you/', [CareersController::class, 'thanks'])->name('careers.thanks');

    Route::get('/legal-assistance/', [EnquiryController::class, 'create'])->name('enquiry.create');
    Route::post('/legal-assistance/', [EnquiryController::class, 'store'])->middleware('throttle:public-forms')->name('enquiry.store');
    Route::get('/legal-assistance/thank-you/', [EnquiryController::class, 'thanks'])->name('enquiry.thanks');
    Route::post('/contact/', [EnquiryController::class, 'storeContact'])->middleware('throttle:public-forms')->name('enquiry.contact');

    Route::get('/feed/', [FeedController::class, 'feed'])->name('feed');
    Route::get('/blog/', [SiteController::class, 'blog'])->name('blog');
    Route::get('/blog/page/{page}/', [SiteController::class, 'blog'])->whereNumber('page');
    Route::get('/category/{slug}/', [SiteController::class, 'category'])->name('category');
    Route::get('/category/{slug}/page/{page}/', [SiteController::class, 'category'])->whereNumber('page');
    Route::get('/tag/{slug}/', [SiteController::class, 'tag'])->name('tag');
    Route::get('/tag/{slug}/page/{page}/', [SiteController::class, 'tag'])->whereNumber('page');

    Route::post('/{slug}/comments', [CommentController::class, 'store'])->where('slug', '[a-z0-9][a-z0-9-]*')->middleware('throttle:public-forms')->name('comments.store');

    // Must be last: CMS page by path, else blog post by slug.
    Route::get('/{slug}/', [SiteController::class, 'resolve'])->where('slug', '(?!(?:admin|portal|preview|livewire|filament|up|storage|build)$)[a-z0-9][a-z0-9-]*')->name('content.show');
});
