<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Clients\ClientRegistration;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\FormGuard;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class RegisterController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(Request $request, ClientRegistration $registration): RedirectResponse
    {
        // Bots (FormGuard) get the same neutral result as a real sign-up.
        if (FormGuard::rejects($request, ['first_name', 'last_name'], 0)) {
            return redirect()->route('login')->with('status', 'Thanks for registering. Please check your email to verify your address.');
        }

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'string', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\s-]{7,40}$/'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'accept_terms' => ['accepted'],
            'accept_privacy' => ['accepted'],
        ], [
            'accept_terms.accepted' => 'Please accept the Terms and Conditions to continue.',
            'accept_privacy.accepted' => 'Please confirm you have read the Privacy Policy to continue.',
        ]);

        // Do not reveal whether an address is registered: existing accounts get the same response,
        // and the owner can use "Lost your password?".
        if (User::where('email', mb_strtolower($data['email']))->exists()) {
            return redirect()->route('login')->with('status', 'Thanks for registering. Please check your email to verify your address.');
        }

        $registration->register($data);

        // No automatic sign-in: the response is identical for new and existing addresses.
        return redirect()->route('login')->with('status', 'Thanks for registering. Please check your email to verify your address.');
    }
}
