<?php

namespace App\Domain\Clients;

use App\Domain\Identity\Role;
use App\Domain\Operations\Audit;
use App\Models\Client;
use App\Models\Page;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Public self-registration: creates a portal user, an individual client record and the client role.
 * Registering gives no access to any matter; staff link matters to the client later.
 */
class ClientRegistration
{
    public const TERMS_PATH = '/terms-and-conditions/';

    public const PRIVACY_PATH = '/privacy-policy/';

    /**
     * @param  array{first_name:string,last_name:string,email:string,password:string,phone?:?string}  $data
     */
    public function register(array $data): User
    {
        $user = DB::transaction(function () use ($data) {
            $name = trim($data['first_name'].' '.$data['last_name']);

            $user = User::create([
                'name' => $name,
                'email' => mb_strtolower($data['email']),
                'password' => Hash::make($data['password']),
                'phone' => $data['phone'] ?? null,
            ]);
            $user->forceFill([
                'terms_accepted_at' => now(),
                'terms_version' => self::documentVersion(self::TERMS_PATH),
                'privacy_accepted_at' => now(),
                'privacy_version' => self::documentVersion(self::PRIVACY_PATH),
            ])->save();

            $user->roleGrants()->create(['role' => Role::Client->value, 'granted_at' => now()]);
            $user->flushRoleCache();

            $client = Client::create([
                'type' => 'individual',
                'display_name' => $name,
                'email' => $user->email,
                'phone' => $user->phone,
            ]);
            $client->users()->attach($user->id, ['relationship' => 'owner']);

            Audit::record('client.self_registered', "Client account registered: {$client->reference}", $client, actor: $user);

            return $user;
        });

        event(new Registered($user)); // sends the verification email

        return $user;
    }

    /** e.g. "terms-r3", or "unpublished" when the document has no published revision yet. */
    public static function documentVersion(string $path): string
    {
        $page = Page::with('publishedRevision:id,number')->where('path', $path)->first();

        return $page?->publishedRevision ? $page->slug.'-r'.$page->publishedRevision->number : 'unpublished';
    }
}
