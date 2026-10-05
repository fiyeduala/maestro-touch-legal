<?php

namespace App\Http\Controllers\Site;

use App\Domain\Intake\Enquiries;
use App\Domain\Intake\EnquirySource;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\User;
use App\Support\FormGuard;
use App\Support\SiteUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Public enquiry forms: the full "legal assistance" form with each service's published intake
 * questions, and the short form on the Contact page. Neither creates representation.
 */
class EnquiryController extends Controller
{
    private const CONTACT_RULES = [
        'contact_name' => ['required', 'string', 'max:160'],
        'contact_email' => ['required', 'email', 'max:190'],
        'contact_phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\s-]{7,40}$/'],
        'summary' => ['required', 'string', 'min:10', 'max:5000'],
        'consent' => ['accepted'],
    ];

    /** Free text checked for web links: spam is mostly links, and one is allowed (D48). */
    private const TEXT_FIELDS = ['contact_name', 'organisation_name', 'summary', 'preferred_times'];

    private const MESSAGES = [
        'consent.accepted' => 'Please confirm you agree to us using these details to respond to your enquiry.',
        'summary.min' => 'Please tell us a little more about what you need.',
        'contact_phone.regex' => 'Enter a valid phone number.',
    ];

    public function create(Request $request): View
    {
        $services = Service::offered()->with('publishedForm')->get();
        $selected = $services->firstWhere('slug', (string) old('service', $request->query('service')));

        return view('enquiries.create', ['services' => $services, 'selected' => $selected]);
    }

    public function store(Request $request, Enquiries $enquiries): RedirectResponse
    {
        if (FormGuard::rejects($request, self::TEXT_FIELDS)) {
            return redirect()->to(SiteUrl::to('/legal-assistance/thank-you/'));
        }

        $services = Service::offered()->with('publishedForm')->get();
        $request->validate(['service' => ['required', Rule::in($services->pluck('slug'))]], ['service.required' => 'Choose the kind of help you need.']);
        /** @var Service $service */
        $service = $services->firstWhere('slug', $request->input('service'));

        $data = $request->validate([
            ...self::CONTACT_RULES,
            'organisation_name' => ['nullable', 'string', 'max:190'],
            'country' => ['nullable', 'string', 'max:80'],
            'preferred_times' => ['nullable', 'string', 'max:500'],
            'answers' => ['nullable', 'array'],
            ...Enquiries::answerRules($service->publishedForm),
        ], self::MESSAGES, $this->answerNames($service));

        $enquiry = $enquiries->submitPublic($data, $service, EnquirySource::Website, $this->client($request), $request->ip());

        return redirect()->to(SiteUrl::to('/legal-assistance/thank-you/'))->with('reference', $enquiry->reference);
    }

    /** The short Contact-page form: no service or intake questions. */
    public function storeContact(Request $request, Enquiries $enquiries): RedirectResponse
    {
        if (FormGuard::rejects($request, self::TEXT_FIELDS)) {
            return redirect()->to(SiteUrl::to('/legal-assistance/thank-you/'));
        }

        $data = $request->validate(self::CONTACT_RULES, self::MESSAGES);
        $enquiry = $enquiries->submitPublic($data, null, EnquirySource::ContactForm, $this->client($request), $request->ip());

        return redirect()->to(SiteUrl::to('/legal-assistance/thank-you/'))->with('reference', $enquiry->reference);
    }

    public function thanks(): View
    {
        return view('enquiries.thanks', ['reference' => session('reference')]);
    }

    /** A signed-in client's enquiry is linked to their client record; staff sessions are not. */
    private function client(Request $request): ?User
    {
        $user = $request->user();

        return $user?->isClient() ? $user : null;
    }

    /** @return array<string, string> readable names for intake answers in error messages */
    private function answerNames(Service $service): array
    {
        return collect($service->publishedForm?->fields ?? [])
            ->mapWithKeys(fn (array $field) => ['answers.'.$field['key'] => '"'.$field['label'].'"'])->all();
    }
}
