<?php

declare(strict_types=1);

namespace App\Model\Latte;

use App\Model\Enum\CategoryStatus;
use App\Model\Enum\ProjectStatus;

final class Filters
{
	/**
	 * Format cents to human-readable money string.
	 * Example: 600010200, 'CZK' → '6 000 102,00 CZK'
	 */
	public static function money(?int $cents, string $currency = 'CZK'): string
	{
		if ($cents === null) {
			return '—';
		}

		return number_format($cents / 100, 2, ',', "\u{00a0}") . "\u{00a0}" . $currency;
	}

	/**
	 * Format amount (already in CZK, not cents) to human-readable string.
	 * Example: 60001.02, 'CZK' → '60 001,02 CZK'
	 */
	public static function moneyAmount(?float $amount, string $currency = 'CZK'): string
	{
		if ($amount === null) {
			return '—';
		}

		return number_format($amount, 2, ',', "\u{00a0}") . "\u{00a0}" . $currency;
	}

	/**
	 * Get Tailwind badge classes for project status.
	 */
	public static function statusBadgeClass(string $status): string
	{
		$enum = ProjectStatus::tryFrom($status);

		return $enum?->badgeClass() ?? 'bg-gray-100 text-gray-800';
	}

	/**
	 * Get translated label for project status.
	 */
	public static function statusLabel(string $status): string
	{
		$enum = ProjectStatus::tryFrom($status);

		return $enum?->label() ?? $status;
	}

	/**
	 * Get Tailwind badge classes for category status.
	 */
	public static function categoryStatusBadgeClass(string $status): string
	{
		$enum = CategoryStatus::tryFrom($status);

		return $enum?->badgeClass() ?? 'bg-gray-100 text-gray-800';
	}

	/**
	 * Get translated label for category status.
	 */
	public static function categoryStatusLabel(string $status): string
	{
		$enum = CategoryStatus::tryFrom($status);

		return $enum?->label() ?? $status;
	}

	/**
	 * Format decimal amount (from DB) to human-readable string.
	 * Example: '60001.02', 'CZK' → '60 001,02 CZK'
	 */
	public static function decimalMoney(?string $amount, string $currency = 'CZK'): string
	{
		if ($amount === null || $amount === '' || $amount === '0.00') {
			return '—';
		}

		return number_format((float) $amount, 2, ',', "\u{00a0}") . "\u{00a0}" . $currency;
	}

	/**
	 * Format file size in bytes to human-readable string.
	 * Example: 1536 → '1.5 KB', 2097152 → '2 MB'
	 */
	public static function fileSize(int $bytes): string
	{
		if ($bytes < 1024) {
			return $bytes . ' B';
		}
		if ($bytes < 1_048_576) {
			return round($bytes / 1024, 1) . ' KB';
		}

		return round($bytes / 1_048_576, 1) . ' MB';
	}
}
