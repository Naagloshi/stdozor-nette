<?php

declare(strict_types=1);

namespace App\Model\Enum;


enum ProjectStatus: string
{
	case Planning = 'planning';
	case Active = 'active';
	case Paused = 'paused';
	case Completed = 'completed';
	case Cancelled = 'cancelled';


	public function label(): string
	{
		return match ($this) {
			self::Planning => 'V přípravě',
			self::Active => 'Probíhá',
			self::Paused => 'Pozastaveno',
			self::Completed => 'Dokončeno',
			self::Cancelled => 'Zrušeno',
		};
	}


	public function badgeClass(): string
	{
		return match ($this) {
			self::Planning => 'bg-blue-100 text-blue-800',
			self::Active => 'bg-green-100 text-green-800',
			self::Paused => 'bg-yellow-100 text-yellow-800',
			self::Completed => 'bg-gray-100 text-gray-800',
			self::Cancelled => 'bg-red-100 text-red-800',
		};
	}


	/**
	 * Sort order for project listing (planning first, cancelled last).
	 */
	public function sortOrder(): int
	{
		return match ($this) {
			self::Planning => 1,
			self::Active => 2,
			self::Paused => 3,
			self::Completed => 4,
			self::Cancelled => 5,
		};
	}


	/**
	 * @return array<string, string> for Nette Forms addSelect()
	 */
	public static function formOptions(): array
	{
		$options = [];
		foreach (self::cases() as $case) {
			$options[$case->value] = $case->label();
		}
		return $options;
	}
}
