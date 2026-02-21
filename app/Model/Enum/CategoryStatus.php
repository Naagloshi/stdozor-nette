<?php

declare(strict_types=1);

namespace App\Model\Enum;


enum CategoryStatus: string
{
	case Planned = 'planned';
	case InProgress = 'in_progress';
	case Completed = 'completed';


	public function label(): string
	{
		return match ($this) {
			self::Planned => 'Plánováno',
			self::InProgress => 'Probíhá',
			self::Completed => 'Dokončeno',
		};
	}


	public function badgeClass(): string
	{
		return match ($this) {
			self::Planned => 'bg-blue-100 text-blue-800',
			self::InProgress => 'bg-green-100 text-green-800',
			self::Completed => 'bg-gray-100 text-gray-800',
		};
	}


	/**
	 * Sort order for display (in_progress first, then planned, then completed).
	 */
	public function sortOrder(): int
	{
		return match ($this) {
			self::Planned => 1,
			self::InProgress => 0,
			self::Completed => 2,
		};
	}


	/**
	 * Whether this status can transition to the given target status.
	 */
	public function canTransitionTo(self $target): bool
	{
		return match ($this) {
			self::Planned => $target === self::InProgress,
			self::InProgress => $target === self::Completed,
			self::Completed => false,
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
