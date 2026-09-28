<?php

namespace App\Filament\Resources\Pages;

use App\Models\Media;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;

/**
 * Editable fields for each page template. The keys match what resources/views/pages/templates/*
 * read, so the stored revision content keeps the same shape as the seeded pages.
 */
class PageContentForm
{
    public const HIGHLIGHT_HELP = 'Wrap words in *asterisks* to show them in the brand blue, as on the live site.';

    /** @return list<\Filament\Schemas\Components\Component> */
    public static function for(string $template): array
    {
        return match ($template) {
            'home' => self::home(),
            'about' => self::about(),
            'offering' => self::offering(),
            'contact' => self::contact(),
            'legal' => self::legal(),
            default => [],
        };
    }

    private static function home(): array
    {
        return [
            Section::make('Hero')->collapsible()->schema([
                self::text('hero.eyebrow', 'Small heading'),
                self::heading('hero.heading'),
                self::text('hero.subheading', 'Sub-heading'),
                self::link('hero.button', 'Button'),
            ]),
            Section::make('Photo band')->collapsible()->collapsed()->columns(2)->schema([
                self::image('band.image'),
                self::text('band.alt', 'Image description (alt text)', required: false),
            ]),
            Section::make('Introduction')->collapsible()->collapsed()->schema([
                self::heading('intro.heading'),
                self::paragraphs('intro.paragraphs'),
                self::image('intro.image'),
                self::alt('intro.image_alt'),
                self::link('intro.link', 'Link'),
            ]),
            Section::make('How it works')->collapsible()->collapsed()->schema([
                self::heading('how.heading'),
                self::numberedItems('how.steps', 'Step'),
            ]),
            Section::make('What we offer')->collapsible()->collapsed()->schema([
                self::heading('offer.heading'),
                self::image('offer.image'),
                self::alt('offer.image_alt'),
                Repeater::make('offer.items')->label('Services')->itemLabel(fn (array $state) => $state['title'] ?? null)
                    ->collapsible()->schema([
                        self::text('title', 'Title'),
                        self::textarea('text', 'Text'),
                    ]),
            ]),
            Section::make('Why choose us')->collapsible()->collapsed()->schema([
                self::heading('why.heading'),
                self::image('why.logo', 'Logo'),
                self::alt('why.logo_alt'),
                Repeater::make('why.items')->label('Points')->simple(self::text('value', 'Point')),
            ]),
            self::cta(),
        ];
    }

    private static function about(): array
    {
        return [
            self::banner(),
            Section::make('Who we are')->collapsible()->schema([
                self::heading('who.heading'),
                self::paragraphs('who.paragraphs'),
                self::image('who.image'),
                self::alt('who.image_alt'),
            ]),
            Section::make('Lead affiliate firm')->collapsible()->collapsed()->schema([
                self::text('affiliate.title', 'Title'),
                self::textarea('affiliate.text', 'Text'),
            ]),
            Section::make('Numbered points')->collapsible()->collapsed()->schema([
                self::numberedItems('points', 'Point'),
            ]),
            Section::make('Vision and mission')->collapsible()->collapsed()->schema([
                Repeater::make('statements')->hiddenLabel()->itemLabel(fn (array $state) => str_replace('*', '', $state['heading'] ?? ''))
                    ->collapsible()->schema([
                        self::heading('heading'),
                        self::textarea('text', 'Text'),
                    ]),
            ]),
            self::cta(),
        ];
    }

    private static function offering(): array
    {
        return [
            self::banner(),
            Section::make('Practice areas')->collapsible()->schema([
                Repeater::make('areas')->hiddenLabel()->itemLabel(fn (array $state) => $state['title'] ?? null)
                    ->collapsible()->collapsed()->schema([
                        self::text('title', 'Title'),
                        self::textarea('text', 'Text'),
                        self::image('image', 'Icon'),
                        self::alt('image_alt'),
                    ]),
            ]),
            Section::make('Virtual notarisation')->collapsible()->collapsed()->schema([
                self::text('notary.heading', 'Heading'),
                self::link('notary.button', 'Button'),
            ]),
            self::cta(),
        ];
    }

    private static function contact(): array
    {
        return [
            self::banner(),
            Section::make('Introduction')->schema([
                self::heading('intro.heading'),
            ]),
            self::cta(),
        ];
    }

    private static function legal(): array
    {
        return [
            self::banner(),
            Section::make('Text')->schema([
                self::textarea('notice', 'Notice shown above the text', required: false)
                    ->helperText('Optional, e.g. a “draft for review” notice. Leave empty for none.'),
                RichEditor::make('body')->hiddenLabel()->required()
                    ->fileAttachments(false)
                    ->toolbarButtons([
                        ['bold', 'italic', 'underline', 'link'],
                        ['h2', 'h3'],
                        ['blockquote', 'bulletList', 'orderedList', 'table'],
                        ['undo', 'redo'],
                    ]),
            ]),
        ];
    }

    // --- Building blocks -----------------------------------------------------

    private static function banner(): Section
    {
        return Section::make('Page banner')->collapsible()->schema([self::heading('banner.heading')]);
    }

    private static function cta(): Section
    {
        return Section::make('Call to action (bottom of page)')->collapsible()->collapsed()->schema([
            self::heading('cta.heading'),
            self::link('cta.button', 'Button'),
        ]);
    }

    private static function text(string $name, string $label, bool $required = true): TextInput
    {
        return TextInput::make($name)->label($label)->maxLength(255)->required($required);
    }

    private static function textarea(string $name, string $label, bool $required = true): Textarea
    {
        return Textarea::make($name)->label($label)->rows(3)->maxLength(3000)->required($required);
    }

    private static function heading(string $name): TextInput
    {
        return self::text($name, 'Heading')->helperText(self::HIGHLIGHT_HELP);
    }

    private static function alt(string $name): TextInput
    {
        return self::text($name, 'Image description (alt text)', required: false)
            ->helperText('Describe the image for people using screen readers. Leave empty only if it is purely decorative.');
    }

    private static function paragraphs(string $name): Repeater
    {
        return Repeater::make($name)->label('Paragraphs')->simple(self::textarea('value', 'Paragraph'))->minItems(1);
    }

    private static function numberedItems(string $name, string $label): Repeater
    {
        return Repeater::make($name)->label($label.'s')->itemLabel(fn (array $state) => trim(($state['number'] ?? '').' '.($state['title'] ?? '')))
            ->collapsible()->columns(6)->schema([
                self::text('number', 'No.')->maxLength(4)->columnSpan(1),
                self::text('title', 'Title')->columnSpan(5),
                self::textarea('text', 'Text')->columnSpanFull(),
            ]);
    }

    private static function link(string $name, string $label): Fieldset
    {
        return Fieldset::make($label)->columns(2)->schema([
            self::text($name.'.label', 'Label'),
            self::text($name.'.url', 'Link')->regex('#^(/|https?://|mailto:|tel:)#i')
                ->helperText('A path on this site such as /contact/, or a full https:// address.'),
        ]);
    }

    /**
     * Images are chosen by path so existing WordPress uploads keep working;
     * suggestions come from the media library.
     */
    public static function image(string $name, string $label = 'Image'): TextInput
    {
        return TextInput::make($name)->label($label)->required()->maxLength(500)
            ->regex('#^(/(?!/)|https://)#i')
            ->datalist(fn () => Media::query()->where('mime_type', 'like', 'image/%')->latest('id')->limit(300)->get()
                ->map(fn (Media $m) => parse_url($m->url(), PHP_URL_PATH))->all())
            ->helperText('Path of an image in the media library, e.g. /media/2026/10/photo.jpg or /wp-content/uploads/...');
    }
}
