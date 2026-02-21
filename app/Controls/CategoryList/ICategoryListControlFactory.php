<?php

declare(strict_types=1);

namespace App\Controls\CategoryList;


interface ICategoryListControlFactory
{
	function create(int $projectId, bool $isOwner, array $memberRoles): CategoryListControl;
}
