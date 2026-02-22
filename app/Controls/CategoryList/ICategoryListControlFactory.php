<?php

declare(strict_types=1);

namespace App\Controls\CategoryList;

interface ICategoryListControlFactory
{
	/**
	 * @param string[] $memberRoles
	 */
	public function create(int $projectId, bool $isOwner, array $memberRoles): CategoryListControl;
}
