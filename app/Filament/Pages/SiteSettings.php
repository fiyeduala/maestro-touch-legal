<?php

namespace App\Filament\Pages;

use App\Domain\Operations\Settings;
use App\Filament\Resources\Pages\PageContentForm;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Firm-wide website settings, stored through Settings::set (typed keys only, audited).
 * There is deliberately no free-form script/HTML field: third-party code is limited to
 * the Tawk.to widget, configured by its IDs and loaded only on public pages.
 *
 * @property-read Schema $form
 */
class SiteSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Settings';

    protected static ?string $slug = 'settings';

    /** Keys this page edits. Others (e.g. firm.timezone) are changed only with a deployment. */
    public const KEYS = [
        'site.title', 'site.tagline', 'site.logo_path', 'site.logo_white_path', 'site.logo_alt', 'site.favicon_path', 'site.og_image_path',
        'footer.text', 'navigation.primary', 'navigation.account_label',
        'brand.primary', 'brand.ink', 'brand.body', 'brand.tint',
        'contact.email', 'contact.phone', 'contact.address', 'contact.whatsapp_number', 'contact.whatsapp_message', 'social.links',
        'mail.from_name', 'mail.from_address', 'mail.reply_to', 'notifications.admin_recipients',
        'integrations.tawk_enabled', 'integrations.tawk_property_id', 'integrations.tawk_widget_id',
        'seo.google_site_verification', 'firm.nvn_url', 'content.editors_can_publish',
        'bank.ngn_bank_name', 'bank.ngn_account_name', 'bank.ngn_account_number', 'bank.ngn_notes',
        'bank.usd_bank_name', 'bank.usd_account_name', 'bank.usd_account_number', 'bank.usd_swift', 'bank.usd_routing',
        'bank.usd_bank_address', 'bank.usd_intermediary', 'bank.usd_notes',
    ];

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isFullAdministrator();
    }

    public function mount(): void
    {
        $values = [];
        foreach (self::KEYS as $key) {
            data_set($values, $key, Settings::get($key));
        }
        $this->form->fill($values);
    }

    public function form(Schema $schema): Schema
    {
        $link = fn (string $name, string $label) => TextInput::make($name)->label($label)->required()->maxLength(500)
            ->regex('#^(/(?!/)|https?://|mailto:|tel:)#i');
        $hex = fn (string $name, string $label) => ColorPicker::make($name)->label($label)->required()->regex('/^#[0-9a-fA-F]{6}$/');
        // A currency's account is either left empty or given all of its required details.
        $anyBank = fn (string $currency) => fn (Get $get) => collect($currency === 'ngn'
            ? ['bank_name', 'account_name', 'account_number']
            : ['bank_name', 'account_name', 'account_number', 'swift'])
            ->contains(fn ($field) => filled($get("bank.{$currency}_{$field}")));

        return $schema->statePath('data')->components([
            Tabs::make()->persistTabInQueryString()->tabs([
                Tab::make('Website')->schema([
                    Section::make('Identity')->columns(2)->schema([
                        TextInput::make('site.title')->label('Site name')->required()->maxLength(120),
                        TextInput::make('site.tagline')->label('Tagline')->maxLength(255),
                        PageContentForm::image('site.logo_path', 'Logo'),
                        PageContentForm::image('site.logo_white_path', 'Logo on dark backgrounds'),
                        TextInput::make('site.logo_alt')->label('Logo description (alt text)')->required()->maxLength(255),
                        PageContentForm::image('site.favicon_path', 'Browser icon'),
                        PageContentForm::image('site.og_image_path', 'Default sharing image'),
                        TextInput::make('footer.text')->label('Footer text')->required()->maxLength(255)
                            ->helperText('{year} is replaced with the current year.'),
                    ]),
                    Section::make('Menu')->schema([
                        Repeater::make('navigation.primary')->hiddenLabel()->columns(2)->reorderable()->maxItems(8)
                            ->schema([
                                TextInput::make('label')->required()->maxLength(40),
                                $link('url', 'Link'),
                            ]),
                        TextInput::make('navigation.account_label')->label('Account button label')->required()->maxLength(40),
                    ]),
                    Section::make('Brand colours')->columns(4)->schema([
                        $hex('brand.primary', 'Primary'),
                        $hex('brand.ink', 'Headings'),
                        $hex('brand.body', 'Body text'),
                        $hex('brand.tint', 'Light background'),
                    ]),
                ]),
                Tab::make('Contact')->schema([
                    Section::make()->columns(2)->schema([
                        TextInput::make('contact.email')->label('Public email')->email()->maxLength(255),
                        TextInput::make('contact.phone')->label('Public phone')->tel()->maxLength(40),
                        Textarea::make('contact.address')->label('Address')->rows(3)->maxLength(500),
                        TextInput::make('contact.whatsapp_number')->label('WhatsApp number')
                            ->regex('/^[1-9][0-9]{7,14}$/')
                            ->helperText('International format, digits only, e.g. 2348012345678. Leave empty to hide WhatsApp.'),
                        Textarea::make('contact.whatsapp_message')->label('WhatsApp starting message')->rows(2)->maxLength(500),
                    ]),
                    Section::make('Social links')->schema([
                        Repeater::make('social.links')->hiddenLabel()->columns(2)->maxItems(10)->schema([
                            TextInput::make('label')->required()->maxLength(40),
                            TextInput::make('url')->required()->url()->regex('#^https://#i')->maxLength(500),
                        ]),
                    ]),
                ]),
                Tab::make('Email')->schema([
                    Section::make()->columns(2)->schema([
                        TextInput::make('mail.from_name')->label('Sender name')->required()->maxLength(120),
                        TextInput::make('mail.from_address')->label('Sender address')->email()->maxLength(255)
                            ->helperText('Must be an address the mail server is allowed to send as. Empty uses the server default.'),
                        TextInput::make('mail.reply_to')->label('Reply-to address')->email()->maxLength(255),
                        TagsInput::make('notifications.admin_recipients')->label('Staff notification recipients')
                            ->nestedRecursiveRules(['email'])
                            ->helperText('Addresses that receive new-enquiry and application alerts. Nothing is sent if this is empty.'),
                    ]),
                ]),
                Tab::make('Bank transfer')->schema([
                    Section::make('Naira (NGN) account')
                        ->description('Clients see these details when paying an NGN invoice by transfer. Leave empty to offer no NGN transfer option.')
                        ->columns(2)->schema([
                            TextInput::make('bank.ngn_bank_name')->label('Bank')->maxLength(120)
                                ->required($anyBank('ngn')),
                            TextInput::make('bank.ngn_account_name')->label('Account name')->maxLength(160)
                                ->required($anyBank('ngn')),
                            TextInput::make('bank.ngn_account_number')->label('Account number (NUBAN)')
                                ->regex('/^\d{10}$/')->validationMessages(['regex' => 'A Nigerian account number has 10 digits.'])
                                ->required($anyBank('ngn')),
                            Textarea::make('bank.ngn_notes')->label('Extra instructions')->rows(2)->maxLength(500),
                        ]),
                    Section::make('US dollar (USD) account')
                        ->description('Clients see these details when paying a USD invoice by transfer. Leave empty to offer no USD transfer option.')
                        ->columns(2)->schema([
                            TextInput::make('bank.usd_bank_name')->label('Bank')->maxLength(120)
                                ->required($anyBank('usd')),
                            TextInput::make('bank.usd_account_name')->label('Account name')->maxLength(160)
                                ->required($anyBank('usd')),
                            TextInput::make('bank.usd_account_number')->label('Account number / IBAN')->maxLength(40)
                                ->regex('/^[A-Za-z0-9 -]{6,40}$/')
                                ->required($anyBank('usd')),
                            TextInput::make('bank.usd_swift')->label('SWIFT / BIC')
                                ->regex('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/')->validationMessages(['regex' => 'Enter an 8 or 11 character SWIFT code in capitals.'])
                                ->required($anyBank('usd')),
                            TextInput::make('bank.usd_routing')->label('Routing / ABA number (if any)')->maxLength(40),
                            TextInput::make('bank.usd_intermediary')->label('Intermediary bank (if any)')->maxLength(255),
                            Textarea::make('bank.usd_bank_address')->label('Bank address')->rows(2)->maxLength(500),
                            Textarea::make('bank.usd_notes')->label('Extra instructions')->rows(2)->maxLength(500),
                        ]),
                ]),
                Tab::make('Integrations')->schema([
                    Section::make('Tawk.to live chat')
                        ->description('Shown on public pages only, never in the client portal or admin. Only the two IDs from the Tawk.to embed code are accepted.')
                        ->columns(2)->schema([
                            Toggle::make('integrations.tawk_enabled')->label('Show the chat widget')->live()->columnSpanFull(),
                            TextInput::make('integrations.tawk_property_id')->label('Property ID')
                                ->regex('/^[a-f0-9]{24}$/')->helperText('24 characters, from the embed code: embed.tawk.to/PROPERTY_ID/WIDGET_ID')
                                ->required(fn (Get $get) => (bool) $get('integrations.tawk_enabled')),
                            TextInput::make('integrations.tawk_widget_id')->label('Widget ID')
                                ->regex('/^[a-z0-9]{6,20}$/i')
                                ->required(fn (Get $get) => (bool) $get('integrations.tawk_enabled')),
                        ]),
                    Section::make('Other')->columns(2)->schema([
                        TextInput::make('seo.google_site_verification')->label('Google site verification code')
                            ->regex('/^[A-Za-z0-9_-]{10,100}$/'),
                        TextInput::make('firm.nvn_url')->label('Naija Virtual Notary address')->url()->regex('#^https://#i')->maxLength(255),
                    ]),
                ]),
                Tab::make('Publishing')->schema([
                    Section::make()->schema([
                        Toggle::make('content.editors_can_publish')->label('Content editors may publish')
                            ->helperText('When off, content editors save drafts and a full administrator publishes them.'),
                    ]),
                ]),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Save settings')->submit('save')->keyBindings(['mod+s']),
                    ])->key('form-actions'),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();
        $values = [];
        foreach (self::KEYS as $key) {
            $value = data_get($state, $key);
            $values[$key] = match (true) {
                is_string($value) => trim($value) === '' ? null : trim($value),
                is_array($value) => array_values($value),
                default => $value,
            };
        }
        $values['site.title'] ??= Settings::DEFINITIONS['site.title'][0];

        Settings::set($values, auth()->user());

        Notification::make()->success()->title('Settings saved')->send();
    }
}
