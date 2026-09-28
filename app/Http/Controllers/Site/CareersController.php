<?php

namespace App\Http\Controllers\Site;

use App\Domain\Identity\AccountAdministrationException;
use App\Domain\Recruitment\StaffApplications;
use App\Http\Controllers\Controller;
use App\Models\StaffApplication;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Public "Join Our Legal Team" form and the applicant's token-protected status page. */
class CareersController extends Controller
{
    /** Option lists shown on the form. Wording is a draft for owner approval (content-gaps). */
    public const CATEGORIES = [
        'lawyer' => 'Lawyer (called to the Nigerian Bar)',
        'affiliate_firm' => 'Affiliate law firm / partner',
        'paralegal' => 'Paralegal / case officer',
        'finance_admin' => 'Finance or administration',
        'content' => 'Legal writing / content',
        'other' => 'Other',
    ];

    public const PRACTICE_AREAS = [
        'business_corporate' => 'Business & Corporate Law',
        'contracts' => 'Contract Drafting & Review',
        'ip' => 'Intellectual Property (IP) Protection',
        'employment' => 'Employment & Workplace Law',
        'regulatory' => 'Government & Regulatory Compliance',
        'data_protection' => 'Data Protection & Privacy Law',
        'immigration' => 'Immigration & Residency Services',
        'adr' => 'Alternative Dispute Resolution (ADR)',
        'notarial' => 'Notarial services',
        'other' => 'Other',
    ];

    private const FILE_RULES = ['file', 'max:10240', 'mimes:pdf,doc,docx', 'mimetypes:application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/octet-stream,application/zip'];

    public function create(): View
    {
        return view('careers.apply', ['categories' => self::CATEGORIES, 'areas' => self::PRACTICE_AREAS]);
    }

    public function store(Request $request, StaffApplications $applications): RedirectResponse
    {
        if ($request->filled('company_website')) {
            return redirect()->route('careers.thanks');
        }

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['required', 'string', 'max:40', 'regex:/^[0-9+()\s-]{7,40}$/'],
            'location' => ['required', 'string', 'max:160'],
            'professional_category' => ['required', Rule::in(array_keys(self::CATEGORIES))],
            'practice_areas' => ['required', 'array', 'min:1', 'max:10'],
            'practice_areas.*' => [Rule::in(array_keys(self::PRACTICE_AREAS))],
            'years_experience' => ['required', 'integer', 'min:0', 'max:60'],
            'qualifications' => ['required', 'string', 'max:3000'],
            'professional_registration' => ['nullable', 'string', 'max:1000'],
            'statement' => ['nullable', 'string', 'max:5000'],
            'cv' => ['required', ...self::FILE_RULES],
            'supporting' => ['nullable', 'array', 'max:3'],
            'supporting.*' => self::FILE_RULES,
            'consent' => ['accepted'],
        ], [
            'consent.accepted' => 'Please confirm you agree to us processing your application.',
            'cv.mimes' => 'Please upload your CV as a PDF or Word document.',
        ]);

        [$application, $token] = $applications->submit($data, $request->file('cv'), $request->file('supporting', []), $request->ip());

        return redirect()->route('careers.thanks')->with('reference', $application->reference);
    }

    public function thanks(): View
    {
        return view('careers.thanks', ['reference' => session('reference')]);
    }

    public function show(string $token): View
    {
        $application = $this->find($token);

        return view('careers.status', [
            'application' => $application->load('applicantVisibleEvents'),
            'token' => $token,
        ]);
    }

    public function respond(Request $request, string $token, StaffApplications $applications): RedirectResponse
    {
        $application = $this->find($token);
        $data = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'files' => ['nullable', 'array', 'max:3'],
            'files.*' => self::FILE_RULES,
        ]);

        try {
            $applications->applicantRespond($application, $data['message'], $request->file('files', []));
        } catch (AccountAdministrationException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        return back()->with('status', 'Thank you. Your response has been sent to the team.');
    }

    public function withdraw(string $token, StaffApplications $applications): RedirectResponse
    {
        try {
            $applications->applicantWithdraw($this->find($token));
        } catch (AccountAdministrationException $e) {
            return back()->withErrors(['withdraw' => $e->getMessage()]);
        }

        return back()->with('status', 'Your application has been withdrawn.');
    }

    private function find(string $token): StaffApplication
    {
        abort_unless(strlen($token) >= 32 && strlen($token) <= 128, 404);

        return StaffApplication::findByApplicantToken($token) ?? abort(404);
    }
}
