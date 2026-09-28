<?php

namespace Database\Seeders;

use App\Domain\Engagement\Engagements;
use App\Models\EngagementTemplate;
use App\Models\Service;
use Illuminate\Database\Seeder;

/**
 * Starting catalogue: the eight practice areas named on the current site's Practice Areas page.
 * Only creates what is missing, so it never overwrites anything an administrator has changed.
 *
 * Intake forms are seeded as unpublished drafts and the sample engagement template is inactive:
 * the questions and the terms are placeholder copy for the owner to review, edit and publish
 * (docs/content-gaps.md §5). Until a form is published the public form asks only the standard
 * contact questions and summary for that service.
 */
class ServiceSeeder extends Seeder
{
    private const BASE_QUESTIONS = [
        ['key' => 'urgency', 'label' => 'How soon do you need help?', 'type' => 'select', 'required' => true,
            'options' => ['Within a week', 'Within a month', 'No fixed deadline']],
        ['key' => 'deadline', 'label' => 'Is there a date we must work to?', 'type' => 'date', 'required' => false,
            'help' => 'For example a filing date or a hearing.'],
    ];

    private const SERVICES = [
        ['Business & Corporate Law', 'business-corporate-law', [
            ['key' => 'business_name', 'label' => 'Business name (if registered or proposed)', 'type' => 'text'],
            ['key' => 'business_stage', 'label' => 'Where is the business now?', 'type' => 'select', 'options' => ['Not yet registered', 'Registered', 'Restructuring or selling']],
        ]],
        ['Contract Drafting & Review', 'contract-drafting-review', [
            ['key' => 'contract_type', 'label' => 'What kind of contract is it?', 'type' => 'text', 'required' => true],
            ['key' => 'contract_task', 'label' => 'What do you need?', 'type' => 'select', 'required' => true, 'options' => ['A new contract drafted', 'An existing contract reviewed']],
        ]],
        ['Intellectual Property (IP) Protection', 'intellectual-property', [
            ['key' => 'ip_type', 'label' => 'What do you want to protect?', 'type' => 'select', 'options' => ['Trademark or brand', 'Copyright', 'Patent or design', 'Not sure']],
        ]],
        ['Employment & Workplace Law', 'employment-workplace-law', [
            ['key' => 'employment_role', 'label' => 'Are you the employer or the employee?', 'type' => 'select', 'required' => true, 'options' => ['Employer', 'Employee']],
        ]],
        ['Government & Regulatory Compliance', 'regulatory-compliance', [
            ['key' => 'regulator', 'label' => 'Which regulator or agency is involved?', 'type' => 'text'],
        ]],
        ['Data Protection & Privacy Law', 'data-protection-privacy', [
            ['key' => 'data_task', 'label' => 'What do you need?', 'type' => 'select', 'options' => ['Privacy policy or notice', 'Compliance review', 'Responding to a data incident', 'Other']],
        ]],
        ['Immigration & Residency Services', 'immigration-residency', [
            ['key' => 'destination', 'label' => 'Which country is this about?', 'type' => 'text', 'required' => true],
        ]],
        ['Alternative Dispute Resolution (ADR)', 'alternative-dispute-resolution', [
            ['key' => 'other_party', 'label' => 'Who is the other party?', 'type' => 'text', 'required' => true,
                'help' => 'We need this to check for conflicts of interest before we can help.'],
        ]],
    ];

    private const TEMPLATE_BODY = <<<'HTML'
<p><strong>DRAFT COPY FOR OWNER APPROVAL. Do not send to clients until the firm has approved this wording.</strong></p>
<h2>Scope of engagement</h2>
<p>[Describe the work the firm will do and what is not included.]</p>
<h2>Fees and expenses</h2>
<p>[Refer to the accepted quotation or set out the fee basis.]</p>
<h2>Responsibilities</h2>
<p>[Client and firm responsibilities, communication and documents.]</p>
<h2>Confidentiality and conflicts</h2>
<p>[Confidentiality, data protection and conflict statements.]</p>
<h2>Ending the engagement</h2>
<p>[How either side may end the engagement and what happens to files.]</p>
HTML;

    public function run(): void
    {
        foreach (self::SERVICES as $sort => [$name, $slug, $questions]) {
            $service = Service::firstOrCreate(['slug' => $slug], [
                'name' => $name, 'is_public' => true, 'is_active' => true, 'sort' => ($sort + 1) * 10,
            ]);
            if (! $service->intakeForms()->exists()) {
                $service->intakeForms()->create([
                    'version' => 1,
                    'fields' => array_map(fn ($field) => $field + ['required' => false, 'options' => [], 'help' => null], [...$questions, ...self::BASE_QUESTIONS]),
                ]);
            }
        }

        EngagementTemplate::firstOrCreate(['name' => 'Standard engagement terms (draft for owner approval)'], [
            'version' => 1,
            'body' => Engagements::clean(self::TEMPLATE_BODY),
            'is_active' => false,
        ]);
    }
}
