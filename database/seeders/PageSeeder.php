<?php

namespace Database\Seeders;

use App\Domain\Content\PageRevisions;
use App\Models\Page;
use Illuminate\Database\Seeder;

/**
 * Mirrored pages from the live WordPress site (docs/content-manifest.json, captured 28 Sep 2026).
 * Copy is verbatim, including suspected typos, which wait for owner approval (docs/content-gaps.md §2).
 * *word* marks the words the live site shows in blue.
 *
 * Idempotent: an existing page (matched by slug) is never overwritten, so owner edits survive a re-seed.
 */
class PageSeeder extends Seeder
{
    private const UPLOADS = '/images/2025/08/';

    public function run(PageRevisions $revisions): void
    {
        foreach ($this->pages() as $spec) {
            if (Page::where('slug', $spec['slug'])->exists()) {
                continue;
            }

            $page = Page::create([
                'slug' => $spec['slug'],
                'path' => $spec['path'],
                'template' => $spec['template'],
                'title' => $spec['title'],
                'is_system' => true,
            ]);

            $revision = $revisions->saveDraft($page, [
                'title' => $spec['title'],
                'meta_description' => $spec['meta_description'] ?? null,
                'noindex' => $spec['noindex'] ?? false,
                'content' => $spec['content'],
            ], note: $spec['note'] ?? 'Imported from the live site');

            if ($spec['publish'] ?? true) {
                $revisions->publish($revision);
            }
        }
    }

    private function cta(): array
    {
        return [
            'heading' => 'Request a *Free* Consultation',
            'button' => ['label' => 'Contact Us', 'url' => '/contact/'],
        ];
    }

    private function pages(): array
    {
        $u = self::UPLOADS;

        return [
            [
                'slug' => 'home',
                'path' => '/',
                'template' => 'home',
                'title' => 'Home',
                'meta_description' => 'Legal Solutions. Anywhere. Anytime. Bringing Professional Legal Services to Your Fingertips From consultations to contract drafting, handled remotely and securely',
                'content' => [
                    'hero' => [
                        'eyebrow' => 'Legal Solutions. Anywhere. Anytime.',
                        'heading' => 'Bringing Professional *Legal Services* to Your *Fingertips*',
                        'subheading' => 'From consultations to contract drafting, handled remotely and securely',
                        'button' => ['label' => 'Sign In', 'url' => '/log-in/'],
                    ],
                    'band' => ['image' => $u.'4-scaled.jpg', 'alt' => ''],
                    'intro' => [
                        'image' => $u.'2152004777-1.jpg',
                        'image_alt' => 'Lawyer reviewing documents at a desk',
                        'heading' => 'Modern *Law* for the Modern *World*',
                        'paragraphs' => [
                            'We’re a forward-thinking virtual law firm offering efficient, client-centered legal services entirely online.',
                            'Whether you’re an individual, startup, or growing business, we provide personalized legal support without the traditional hassle or overhead.',
                        ],
                        'link' => ['label' => 'Learn more', 'url' => '/about/'],
                    ],
                    'how' => [
                        'heading' => 'How it works?',
                        'steps' => [
                            ['number' => '01', 'title' => 'Submit a Request', 'text' => 'Tell us what you need in just a few clicks.'],
                            ['number' => '02', 'title' => 'Meet Your Lawyer', 'text' => 'Get matched with a qualified legal professional.'],
                            ['number' => '03', 'title' => 'Get Legal Support Online', 'text' => 'Receive legal advice, documents, or representation — all handled remotely.'],
                        ],
                    ],
                    'offer' => [
                        'heading' => 'What We *Offer*?',
                        'image' => $u.'offer-1.png',
                        'image_alt' => 'Lawyer holding a briefcase',
                        'items' => [
                            ['title' => 'Remote Legal Consultations', 'text' => 'Speak directly with qualified legal professionals — all online, on your schedule.'],
                            ['title' => 'Document Drafting & Review', 'text' => 'rom contracts to legal letters, we help draft, review, and refine the documents you need.'],
                            ['title' => 'Business & Personal Legal Services', 'text' => 'Whether you’re setting up a business or managing personal matters, our services are designed to support your legal journey from start to finish.'],
                            ['title' => 'Flat Fees. Transparent Pricing', 'text' => 'No surprises. Know what you’re paying for — upfront.'],
                        ],
                    ],
                    'why' => [
                        'heading' => 'Why *Choose* Us?',
                        'logo' => $u.'blue-2.png',
                        'logo_alt' => 'Maestro Touch Legal',
                        'items' => [
                            'Experienced Legal Professionals',
                            '100% Online: No Office Visits Required',
                            'Secure & Confidential Processes',
                            'Mobile-Friendly Client Portal',
                            'Fast Communication & Ongoing Support',
                        ],
                    ],
                    'cta' => $this->cta(),
                ],
            ],
            [
                'slug' => 'about',
                'path' => '/about/',
                'template' => 'about',
                'title' => 'About',
                'meta_description' => 'We are a modern, fully virtual law firm dedicated to making legal services more accessible, convenient, and transparent.',
                'content' => [
                    'banner' => ['heading' => 'About *Us*'],
                    'who' => [
                        'heading' => 'Who *We* Are',
                        'paragraphs' => [
                            'We are a modern, fully virtual law firm dedicated to making legal services more accessible, convenient, and transparent. Our mission is simple: to deliver professional legal support without the barriers of location, long wait times, or excessive legal fees.',
                            'Our team of qualified legal professionals works entirely online, providing the same quality, confidentiality, and expertise you’d expect from a traditional firm with the added benefit of digital efficiency.',
                        ],
                        'image' => $u.'Zz00YWRjYTdjYzhlZDExMWVlOTVhOGJlNTYwN2JiNDhkNA.jpg',
                        'image_alt' => 'Two professionals meeting with a laptop',
                    ],
                    'affiliate' => [
                        'title' => 'Our Lead Affiliate Firm',
                        'text' => 'Tim Lebura Kip & Co. (TLK) serves as the Lead Affiliate Firm of Maestro Touch Legal. With offices in Abuja, Port Harcourt, and Calabar, TLK provides in-depth regional knowledge and on-the-ground representation when in-person legal support is required. This partnership ensures that our virtual capabilities are complemented by physical presence in key Nigerian cities.',
                        'image' => '/images/2025/08/tlk.jpg',
                        'image_alt' => 'Maestro Law – Tim Lebura Kip & Co. logo',
                    ],
                    'points' => [
                        ['number' => '01', 'title' => 'Why We’re Different', 'text' => 'Unlike traditional law firms, we operate entirely online — meaning no travel, no waiting rooms, and no unnecessary paperwork. Our secure client portal keeps you connected with your lawyer, updates you in real-time, and stores your legal documents safely in one place'],
                        ['number' => '02', 'title' => 'How We Can Help You', 'text' => 'Get matched with a qualified legal professional.'],
                        ['number' => '03', 'title' => 'Get Legal Support Online', 'text' => 'Receive legal advice, documents, or representation — all handled remotely.'],
                    ],
                    'statements' => [
                        ['heading' => 'Our *Vision*', 'text' => 'A future where legal services are as easy to access as any other online service — without compromising on quality or ethics.'],
                        ['heading' => 'Our *Mission*', 'text' => 'To provide clients with clear, affordable, and results-driven legal solutions that fit into today’s fast-paced, digital-first world.'],
                    ],
                    'cta' => $this->cta(),
                ],
            ],
            [
                'slug' => 'offering',
                'path' => '/offering/',
                'template' => 'offering',
                'title' => 'Practice areas',
                'meta_description' => 'Practice Areas: Business & Corporate Law, Contract Drafting & Review, Intellectual Property, Employment, Regulatory Compliance, Data Protection, Immigration and ADR.',
                'content' => [
                    'banner' => ['heading' => 'Practice Areas'],
                    'areas' => [
                        ['title' => 'Business & Corporate Law', 'text' => 'Helping startups, established companies, and government contractors navigate legal requirements.', 'image' => $u.'business-and-corporate.png', 'image_alt' => 'Business & Corporate Law'],
                        ['title' => 'Contract Drafting & Review', 'text' => 'Clear, enforceable agreements are key to protecting your interests.', 'image' => $u.'contract-drafting.png', 'image_alt' => 'Contract Drafting & Review'],
                        ['title' => 'Intellectual Property (IP) Protection', 'text' => 'Safeguard your creative works, brand, and innovations.', 'image' => $u.'IP.png', 'image_alt' => 'Intellectual Property Protection'],
                        ['title' => 'Employment & Workplace Law', 'text' => 'Guidance for employers, employees, and agencies to ensure compliance and fairness.', 'image' => $u.'employment.png', 'image_alt' => 'Employment & Workplace Law'],
                        ['title' => 'Government & Regulatory Compliance', 'text' => 'Support for organizations working with or within government frameworks', 'image' => $u.'govt.png', 'image_alt' => 'Government & Regulatory Compliance'],
                        ['title' => 'Data Protection & Privacy Law', 'text' => 'Ensure your organization’s handling of personal data meets legal requirements', 'image' => $u.'data-protection-.png', 'image_alt' => 'Data Protection & Privacy Law'],
                        ['title' => 'Immigration & Residency Services', 'text' => 'Helping individuals and organizations navigate immigration processes', 'image' => $u.'immigration.png', 'image_alt' => 'Immigration & Residency Services'],
                        ['title' => 'Alternative Dispute Resolution (ADR)', 'text' => 'Resolve disputes efficiently without lengthy litigation', 'image' => $u.'adr.png', 'image_alt' => 'Alternative Dispute Resolution'],
                    ],
                    'notary' => [
                        'heading' => 'Virtual Notarization',
                        'button' => ['label' => 'Proceed to Notarize', 'url' => 'https://naijavirtualnotary.mtouchlegal.com/'],
                    ],
                    'cta' => $this->cta(),
                ],
            ],
            [
                'slug' => 'contact',
                'path' => '/contact/',
                'template' => 'contact',
                'title' => 'Contact',
                'meta_description' => "Contact Us. Let's be Your Trusted Legal Partner in Innovation. Request a Free Consultation.",
                'content' => [
                    'banner' => ['heading' => 'Contact Us'],
                    'intro' => ['heading' => "Let's be Your Trusted Legal Partner in Innovation"],
                    'cta' => $this->cta(),
                ],
            ],
            [
                'slug' => 'terms-and-conditions',
                'path' => '/terms-and-conditions/',
                'template' => 'legal',
                'title' => 'Terms and conditions',
                'meta_description' => 'Terms and Conditions for the Maestro Touch Legal Client Portal.',
                'content' => [
                    'banner' => ['heading' => 'Terms *and* Conditions'],
                    // Stored as captured; rendered through the "content" purifier profile.
                    'body' => file_get_contents(__DIR__.'/content/terms-and-conditions.html'),
                ],
            ],
            [
                'slug' => 'privacy-policy',
                'path' => '/privacy-policy/',
                'template' => 'legal',
                'title' => 'Privacy Policy',
                'noindex' => true,
                'publish' => false,
                'note' => 'DRAFT for owner/legal review. Not published. The live site has no privacy policy page.',
                'content' => [
                    'banner' => ['heading' => 'Privacy Policy'],
                    'notice' => 'Draft for review. This text has not been approved by Maestro Touch Legal.',
                    'body' => file_get_contents(__DIR__.'/content/privacy-policy-draft.html'),
                ],
            ],
        ];
    }
}
