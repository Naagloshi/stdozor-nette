<?php

declare(strict_types=1);

namespace App\Model\Enum;

enum ProjectRole: string
{
	case Owner = 'owner';
	case Supervisor = 'supervisor';
	case Contractor = 'contractor';
	case Investor = 'investor';

	public function label(): string
	{
		return match ($this) {
			self::Owner => 'Vlastník projektu',
			self::Supervisor => 'Technický dozor investor (TDI)',
			self::Contractor => 'Zhotovitel',
			self::Investor => 'Investor',
		};
	}
}
