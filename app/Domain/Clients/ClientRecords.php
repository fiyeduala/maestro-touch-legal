<?php

namespace App\Domain\Clients;

use App\Domain\Operations\Audit;
use App\Domain\RuleViolation;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** Client details. Clients are created from enquiries (Enquiries::createClient) and never deleted. */
class ClientRecords
{
    public const EDITABLE = ['type', 'display_name', 'organisation_name', 'registration_number', 'email', 'phone', 'country', 'timezone', 'preferred_currency', 'address', 'relationship_owner_id'];

    public const TYPES = ['individual' => 'Individual', 'organisation' => 'Organisation'];

    public function update(Client $client, array $data, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $client);
        $data = array_intersect_key($data, array_flip(self::EDITABLE));
        if (trim((string) ($data['display_name'] ?? $client->display_name)) === '') {
            throw new RuleViolation('Enter the client name.');
        }
        if (isset($data['type']) && ! isset(self::TYPES[$data['type']])) {
            throw new RuleViolation('Choose individual or organisation.');
        }
        if (isset($data['timezone']) && ! in_array($data['timezone'], timezone_identifiers_list(), true)) {
            throw new RuleViolation('Choose a valid time zone.');
        }
        if (isset($data['preferred_currency']) && ! preg_match('/^[A-Z]{3}$/', $data['preferred_currency'])) {
            throw new RuleViolation('Use a three-letter currency code.');
        }
        if (filled($data['relationship_owner_id'] ?? null) && ! User::whereKey($data['relationship_owner_id'])->first()?->isStaff()) {
            throw new RuleViolation('The relationship owner must be a staff member.');
        }

        $client->fill(array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, $data));
        $dirty = $client->getDirty();
        if ($dirty === []) {
            return;
        }
        $before = array_intersect_key($client->getOriginal(), $dirty);
        $client->save();
        Audit::record('client.updated', "Client {$client->reference} details updated (".implode(', ', array_keys($dirty)).')', $client,
            ['before' => $before, 'after' => $dirty], actor: $actor);
    }
}
