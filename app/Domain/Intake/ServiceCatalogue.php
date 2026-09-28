<?php

namespace App\Domain\Intake;

use App\Domain\Engagement\Engagements;
use App\Domain\Matters\MatterStatus;
use App\Domain\Operations\Audit;
use App\Domain\RuleViolation;
use App\Models\EngagementTemplate;
use App\Models\IntakeForm;
use App\Models\Matter;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Firm configuration for intake (spec §6): services with their matter stages, versioned intake
 * forms, and engagement templates. Full administrators only. Nothing here is ever hard-deleted:
 * services and templates are deactivated, and a published form version is never edited.
 */
class ServiceCatalogue
{
    private const KEY = '/^[a-z][a-z0-9_]{1,39}$/';

    /**
     * @param  array{name: string, slug?: ?string, summary?: ?string, is_public?: bool, is_active?: bool, sort?: ?int, stages?: ?array}  $data
     */
    public function saveService(?Service $service, array $data, User $actor): Service
    {
        $service
            ? Gate::forUser($actor)->authorize('update', $service)
            : Gate::forUser($actor)->authorize('create', Service::class);

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new RuleViolation('Enter the service name.');
        }
        $slug = Str::slug(filled($data['slug'] ?? null) ? $data['slug'] : $name);
        if (Service::where('slug', $slug)->when($service, fn ($query) => $query->whereKeyNot($service->id))->exists()) {
            throw new RuleViolation("Another service already uses the address \"{$slug}\".");
        }
        $stages = $this->stages($data['stages'] ?? null);
        if ($service) {
            $this->assertStagesStillCoverOpenMatters($service, $stages);
        }

        return DB::transaction(function () use ($service, $data, $name, $slug, $stages, $actor) {
            $service ??= new Service;
            $before = $service->exists ? $service->only(['name', 'slug', 'is_public', 'is_active', 'stages']) : null;
            $service->fill([
                'name' => $name,
                'slug' => $slug,
                'summary' => filled($data['summary'] ?? null) ? trim($data['summary']) : null,
                'is_public' => (bool) ($data['is_public'] ?? true),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'sort' => (int) ($data['sort'] ?? 0),
                'stages' => $stages,
            ])->save();

            Audit::record($before ? 'service.updated' : 'service.created', "Service \"{$service->name}\" ".($before ? 'updated' : 'created'), $service,
                $before ? ['before' => $before, 'after' => $service->only(array_keys($before))] : null, actor: $actor);

            return $service;
        });
    }

    /**
     * Saves the next intake form draft. If the latest version is still a draft it is replaced;
     * otherwise a new version is started, so what enquirers already answered is never rewritten.
     *
     * @param  list<array{key?: ?string, label: string, type: string, required?: bool, options?: mixed, help?: ?string}>  $fields
     */
    public function saveFormDraft(Service $service, array $fields, User $actor): IntakeForm
    {
        Gate::forUser($actor)->authorize('update', $service);
        $fields = $this->fields($fields);

        return DB::transaction(function () use ($service, $fields, $actor) {
            $latest = $service->intakeForms()->lockForUpdate()->first();
            $form = $latest && ! $latest->isPublished()
                ? tap($latest)->update(['fields' => $fields])
                : $service->intakeForms()->create([
                    'version' => ($latest?->version ?? 0) + 1,
                    'fields' => $fields,
                    'created_by' => $actor->id,
                ]);
            Audit::record('intake_form.saved', "Intake form v{$form->version} draft saved for \"{$service->name}\" (".count($fields).' questions)', $form, actor: $actor);

            return $form;
        });
    }

    /** Publishing makes this version the one the public form uses. It cannot be edited afterwards. */
    public function publishForm(IntakeForm $form, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $form->service);
        if ($form->isPublished()) {
            throw new RuleViolation('This version is already published.');
        }
        if ($form->service->intakeForms()->where('version', '>', $form->version)->exists()) {
            throw new RuleViolation('A newer version exists. Publish that one instead.');
        }
        $form->update(['published_at' => now(), 'published_by' => $actor->id]);
        Audit::record('intake_form.published', "Intake form v{$form->version} published for \"{$form->service->name}\"", $form, actor: $actor);
    }

    /**
     * Template changes raise the version. Terms already prepared keep their own copy and the
     * template version they came from.
     *
     * @param  array{name: string, service_id?: ?int, body: string, is_active?: bool}  $data
     */
    public function saveTemplate(?EngagementTemplate $template, array $data, User $actor): EngagementTemplate
    {
        $template
            ? Gate::forUser($actor)->authorize('update', $template)
            : Gate::forUser($actor)->authorize('create', EngagementTemplate::class);

        $name = trim((string) ($data['name'] ?? ''));
        $body = Engagements::clean((string) ($data['body'] ?? ''));
        if ($name === '' || trim(strip_tags($body)) === '') {
            throw new RuleViolation('Enter a name and the terms.');
        }

        return DB::transaction(function () use ($template, $data, $name, $body, $actor) {
            $template ??= new EngagementTemplate(['version' => 1]);
            $wasNew = ! $template->exists;
            $template->fill([
                'name' => $name,
                'service_id' => $data['service_id'] ?? null,
                'body' => $body,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'updated_by' => $actor->id,
            ]);
            // Compare against the cleaned original so re-saving unchanged terms does not raise the version.
            if (! $wasNew && $body !== Engagements::clean((string) $template->getOriginal('body'))) {
                $template->version++;
            }
            $dirty = array_keys($template->getDirty());
            $template->save();
            Audit::record($wasNew ? 'engagement_template.created' : 'engagement_template.updated',
                "Engagement template \"{$template->name}\" v{$template->version} ".($wasNew ? 'created' : 'updated ('.implode(', ', $dirty).')'), $template, actor: $actor);

            return $template;
        });
    }

    /** @return list<array{key: string, label: string}>|null null means the default stages */
    private function stages(?array $rows): ?array
    {
        $rows = array_values(array_filter($rows ?? [], fn ($row) => filled($row['label'] ?? null)));
        if ($rows === []) {
            return null;
        }

        $out = [];
        foreach ($rows as $row) {
            $key = filled($row['key'] ?? null) ? trim($row['key']) : Str::snake(Str::ascii(trim($row['label'])));
            if (! preg_match(self::KEY, $key)) {
                throw new RuleViolation("Stage key \"{$key}\" must start with a letter and use only lower-case letters, numbers and underscores.");
            }
            if (isset($out[$key])) {
                throw new RuleViolation("Two stages share the key \"{$key}\".");
            }
            $out[$key] = ['key' => $key, 'label' => trim($row['label'])];
        }

        return array_values($out);
    }

    /** A stage still used by an open or on-hold matter cannot be removed. */
    private function assertStagesStillCoverOpenMatters(Service $service, ?array $stages): void
    {
        $keys = array_column($stages ?? Service::DEFAULT_STAGES, 'key');
        $inUse = Matter::where('service_id', $service->id)->where('status', '!=', MatterStatus::Closed->value)
            ->whereNotIn('stage', $keys)->distinct()->pluck('stage');
        if ($inUse->isNotEmpty()) {
            throw new RuleViolation('Open matters are still at stage(s): '.$inUse->implode(', ').'. Keep those stages or move the matters first.');
        }
    }

    /** @return list<array{key: string, label: string, type: string, required: bool, options: list<string>, help: ?string}> */
    private function fields(array $rows): array
    {
        $out = [];
        foreach (array_values($rows) as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $key = filled($row['key'] ?? null) ? trim($row['key']) : Str::limit(Str::snake(Str::ascii($label)), 40, '');
            $type = $row['type'] ?? '';
            if (! preg_match(self::KEY, $key)) {
                throw new RuleViolation("Question key \"{$key}\" must start with a letter and use only lower-case letters, numbers and underscores.");
            }
            if (isset($out[$key])) {
                throw new RuleViolation("Two questions share the key \"{$key}\".");
            }
            if (! isset(IntakeForm::FIELD_TYPES[$type])) {
                throw new RuleViolation("Choose a valid answer type for \"{$label}\".");
            }
            $options = $row['options'] ?? [];
            $options = array_values(array_unique(array_filter(array_map('trim', is_array($options) ? $options : preg_split('/\R/', (string) $options)))));
            if ($type === 'select' && count($options) < 2) {
                throw new RuleViolation("\"{$label}\" needs at least two choices.");
            }
            $out[$key] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'required' => (bool) ($row['required'] ?? false),
                'options' => $type === 'select' ? $options : [],
                'help' => filled($row['help'] ?? null) ? trim($row['help']) : null,
            ];
        }
        if (count($out) > 40) {
            throw new RuleViolation('An intake form can have at most 40 questions.');
        }

        return array_values($out);
    }
}
