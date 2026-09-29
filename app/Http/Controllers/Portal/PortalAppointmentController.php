<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Consultations\Consultations;
use App\Domain\Operations\Settings;
use App\Domain\RuleViolation;
use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\ConsultationType;
use App\Models\Matter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Consultation requests from the portal. Clients pick from the published slots (server-rendered,
 * so it works without JavaScript); the firm confirms each request. The client's own agenda is shown
 * back to them; internal outcome notes never are.
 */
class PortalAppointmentController extends Controller
{
    public function __construct(private Consultations $consultations) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $clients = $user->clients()->orderBy('display_name')->get();
        $types = ConsultationType::bookable()->get();
        $type = $types->firstWhere('id', $request->integer('type')) ?? $types->first();
        $all = Consultation::whereIn('client_id', $clients->modelKeys())->with(['type', 'matter:id,reference,title'])->latest('starts_at')->limit(50)->get();

        return view('portal.appointments', [
            'clients' => $clients,
            'types' => $types,
            'type' => $type,
            'slots' => $type ? $this->consultations->slots($type) : [],
            'matters' => Matter::whereIn('client_id', $clients->modelKeys())->where('status', '!=', 'closed')->orderBy('reference')->get(['id', 'client_id', 'reference', 'title']),
            'upcoming' => $all->filter(fn (Consultation $c) => $c->isActive() && $c->ends_at->isFuture())->sortBy('starts_at')->values(),
            'past' => $all->reject(fn (Consultation $c) => $c->isActive() && $c->ends_at->isFuture())->values(),
            'cutoffHours' => (int) Settings::get('consultations.client_change_cutoff_hours'),
            'canBook' => ! $user->isFullAdministrator() && ! $user->isStaff(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'type_id' => ['required', 'integer'],
            'slot' => ['required', 'date'],
            'matter_id' => ['nullable', 'integer'],
            'agenda' => ['nullable', 'string', 'max:2000'],
        ], ['slot.required' => 'Choose a time.']);

        $client = $user->clients()->whereKey($data['client_id'])->first();
        $type = ConsultationType::bookable()->find($data['type_id']);
        $matter = ! empty($data['matter_id']) ? Matter::where('client_id', $client?->id)->find($data['matter_id']) : null;
        if (! $client || ! $type || (! empty($data['matter_id']) && ! $matter)) {
            return back()->withInput()->withErrors(['slot' => 'Please choose again.']);
        }

        return $this->attempt(fn () => $this->consultations->request($user, $client, $type, Carbon::parse($data['slot']), $data['agenda'] ?? null, $matter),
            'Your request has been sent. The firm will confirm the time by email.');
    }

    public function reschedule(Request $request, Consultation $consultation): RedirectResponse
    {
        Gate::authorize('actAsClient', $consultation);
        $data = $request->validate(['slot' => ['required', 'date']], ['slot.required' => 'Choose a new time.']);

        return $this->attempt(fn () => $this->consultations->reschedule($consultation, Carbon::parse($data['slot']), $request->user()),
            'Your consultation has been moved. The firm will confirm the new time by email.', 'reschedule_'.$consultation->id);
    }

    public function cancel(Request $request, Consultation $consultation): RedirectResponse
    {
        Gate::authorize('actAsClient', $consultation);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        return $this->attempt(fn () => $this->consultations->cancel($consultation, $data['reason'] ?? null, $request->user()),
            'Your consultation has been cancelled.', 'reschedule_'.$consultation->id);
    }

    private function attempt(callable $callback, string $success, string $errorKey = 'slot'): RedirectResponse
    {
        try {
            $callback();
        } catch (RuleViolation $e) {
            return back()->withInput()->withErrors([$errorKey => $e->getMessage()]);
        }

        return redirect()->route('portal.appointments')->with('status', $success);
    }
}
