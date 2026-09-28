<?php

namespace App\Domain\Intake;

enum EnquirySource: string
{
    case Website = 'website';
    case ContactForm = 'contact_form';
    case Portal = 'portal';
    case Phone = 'phone';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case Referral = 'referral';
    case WalkIn = 'walk_in';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Website => 'Website legal-assistance form',
            self::ContactForm => 'Website contact form',
            self::Portal => 'Client portal',
            self::Phone => 'Phone',
            self::WhatsApp => 'WhatsApp',
            self::Email => 'Email',
            self::Referral => 'Referral',
            self::WalkIn => 'In person',
            self::Other => 'Other',
        };
    }

    /** Sources a staff member can record when entering an enquiry by hand. @return array<string, string> */
    public static function staffOptions(): array
    {
        return collect([self::Phone, self::WhatsApp, self::Email, self::Referral, self::WalkIn, self::Other])
            ->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
