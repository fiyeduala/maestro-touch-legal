<?php

namespace App\Domain\Identity;

/**
 * The fixed set of roles. What each role may do is decided by policies and the
 * capability checks below, so docs/permission-matrix.md stays true by construction.
 */
enum Role: string
{
    case TechnicalAdministrator = 'technical_admin';
    case FirmPrincipal = 'firm_principal';
    case Lawyer = 'lawyer';
    case CaseOfficer = 'case_officer';
    case FinanceOfficer = 'finance_officer';
    case ContentEditor = 'content_editor';
    case Client = 'client';

    public function label(): string
    {
        return match ($this) {
            self::TechnicalAdministrator => 'Technical Administrator',
            self::FirmPrincipal => 'Firm Principal / Managing Lawyer',
            self::Lawyer => 'Lawyer / Affiliate Lawyer',
            self::CaseOfficer => 'Case Officer / Support Staff',
            self::FinanceOfficer => 'Finance Officer',
            self::ContentEditor => 'Content Editor',
            self::Client => 'Client',
        };
    }

    public function isFullAdministrator(): bool
    {
        return in_array($this, self::fullAdministratorRoles(), true);
    }

    public function isStaff(): bool
    {
        return $this !== self::Client;
    }

    /** @return list<self> */
    public static function fullAdministratorRoles(): array
    {
        return [self::TechnicalAdministrator, self::FirmPrincipal];
    }

    /** @return list<self> */
    public static function staffRoles(): array
    {
        return array_values(array_filter(self::cases(), fn (self $r) => $r->isStaff()));
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(bool $staffOnly = false): array
    {
        $roles = $staffOnly ? self::staffRoles() : self::cases();

        return collect($roles)->mapWithKeys(fn (self $r) => [$r->value => $r->label()])->all();
    }
}
